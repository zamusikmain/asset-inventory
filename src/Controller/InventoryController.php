<?php

namespace App\Controller;

use App\Entity\HandoverDocument;
use App\Entity\InventoryCampaign;
use App\Entity\InventoryCommissionMember;
use App\Entity\InventoryDiscrepancy;
use App\Entity\InventoryScan;
use App\Entity\User;
use App\Enum\CampaignStatus;
use App\Enum\ScanResult;
use App\Exception\BusinessException;
use App\Form\CampaignFormType;
use App\Repository\AssetRepository;
use App\Repository\InventoryCampaignRepository;
use App\Repository\LocationRepository;
use App\Service\AuditLogger;
use App\Service\HandoverService;
use App\Service\InventoryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Инвентаризация (п. 12 ТЗ).
 */
#[Route('/inventory')]
#[IsGranted('ROLE_USER')]
class InventoryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {
    }

    private function csrf(string $id, Request $r): void
    {
        if (!$this->isCsrfTokenValid($id, $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function user(): User
    {
        /** @var User $u */
        $u = $this->getUser();

        return $u;
    }

    private function canManage(InventoryCampaign $c): bool
    {
        return $this->isGranted('ROLE_ADMIN') || ($this->isGranted('ROLE_INVENTORY_INITIATOR') && $c->getInitiator() === $this->user());
    }

    private function canResolve(InventoryCampaign $c): bool
    {
        return $this->isGranted('ROLE_IT');
    }

    #[Route('', name: 'inventory_index')]
    public function index(InventoryCampaignRepository $repo): Response
    {
        return $this->render('inventory/index.html.twig', ['campaigns' => $repo->findBy([], ['id' => 'DESC'])]);
    }

    #[Route('/new', name: 'inventory_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_INVENTORY_INITIATOR')]
    public function new(Request $r, InventoryCampaignRepository $repo): Response
    {
        $c = (new InventoryCampaign())->setNumber($repo->nextNumber())->setInitiator($this->user());

        return $this->form($c, $r, true);
    }

    #[Route('/{id}/edit', name: 'inventory_edit', methods: ['GET', 'POST'])]
    public function edit(InventoryCampaign $c, Request $r): Response
    {
        if (!$this->canManage($c)) {
            throw $this->createAccessDeniedException();
        }
        if (!in_array($c->getStatus(), [CampaignStatus::Draft, CampaignStatus::Preparation], true)) {
            throw new BusinessException('Параметры кампании можно менять только в статусах «Черновик» и «Подготовка»');
        }

        return $this->form($c, $r, false);
    }

    private function form(InventoryCampaign $c, Request $r, bool $isNew): Response
    {
        $members = array_map(fn (InventoryCommissionMember $m) => $m->getUser(), $c->getCommission()->toArray());
        $chair = null;
        foreach ($c->getCommission() as $m) {
            if ($m->isChairman()) {
                $chair = $m->getUser();
            }
        }
        $form = $this->createForm(CampaignFormType::class, $c, ['commission' => $members, 'chairman' => $chair]);
        $form->handleRequest($r);
        if ($form->isSubmitted() && $form->isValid()) {
            $chair = $form->get('chairman')->getData();
            $selected = $form->get('commissionUsers')->getData();
            $selected = $selected instanceof \Traversable ? iterator_to_array($selected) : (array) $selected;
            if ($chair && !in_array($chair, $selected, true)) {
                $selected[] = $chair;
            }
            foreach ($c->getCommission()->toArray() as $m) {
                if (!in_array($m->getUser(), $selected, true)) {
                    $c->getCommission()->removeElement($m);
                }
            }
            foreach ($selected as $u) {
                if (!$c->isCommissionMember($u)) {
                    $c->addCommissionMember(new InventoryCommissionMember($c, $u, $u === $chair));
                }
            }
            $this->em->persist($c);
            $this->em->flush();
            $this->audit->log($isNew ? 'inventory.campaign_create' : 'inventory.campaign_update', ['objectType' => 'inventory_campaign', 'objectId' => (string) $c->getId(), 'objectLabel' => (string) $c,
                'new' => ['locations' => array_map('strval', $c->getLocations()->toArray()), 'commission' => array_map('strval', $selected)]], true);
            $this->addFlash('success', 'Кампания сохранена');

            return $this->redirectToRoute('inventory_show', ['id' => $c->getId()]);
        }

        return $this->render('inventory/form.html.twig', ['form' => $form, 'campaign' => $c, 'is_new' => $isNew]);
    }

    #[Route('/{id}', name: 'inventory_show', requirements: ['id' => '\d+'])]
    public function show(InventoryCampaign $c, Request $r): Response
    {
        $discFilter = $r->query->getString('d', 'open');
        $crit = ['campaign' => $c];
        if ($discFilter !== 'all') {
            $crit['status'] = $discFilter;
        }

        return $this->render('inventory/show.html.twig', [
            'c' => $c,
            'summary' => $this->inventory->summary($c),
            'discrepancies' => $this->em->getRepository(InventoryDiscrepancy::class)->findBy($crit, ['id' => 'DESC'], 500),
            'recent_scans' => $this->em->getRepository(InventoryScan::class)->findBy(['campaign' => $c], ['receivedAt' => 'DESC'], 30),
            'can_manage' => $this->canManage($c),
            'can_resolve' => $this->canResolve($c),
            'is_member' => $c->isCommissionMember($this->user()),
            'd' => $discFilter,
            'acts' => $this->em->getRepository(HandoverDocument::class)->findBy(['campaign' => $c], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/{id}/status', name: 'inventory_status', methods: ['POST'])]
    public function status(InventoryCampaign $c, Request $r): Response
    {
        $this->csrf('campaign' . $c->getId(), $r);
        $to = CampaignStatus::tryFrom($r->request->getString('status')) ?? throw new BusinessException('Неизвестный статус');
        $itSteps = [CampaignStatus::AwaitingReview, CampaignStatus::WithCommission, CampaignStatus::InProgress];
        if (!$this->canManage($c) && !(in_array($to, $itSteps, true) && $this->isGranted('ROLE_IT'))) {
            throw $this->createAccessDeniedException();
        }
        $this->inventory->transition($c, $to, $this->user(), trim($r->request->getString('comment')) ?: null);
        $this->em->flush();
        $this->addFlash('success', 'Статус кампании: ' . $to->label());

        return $this->redirectToRoute('inventory_show', ['id' => $c->getId()]);
    }

    /** Страница USB-сканирования: фокус в поле, мгновенный результат (п. 10.1). */
    #[Route('/{id}/scan', name: 'inventory_scan')]
    public function scan(InventoryCampaign $c, InventoryService $inv): Response
    {
        if (!$this->isGranted('ROLE_IT') && !$c->getItResponsible()->contains($this->user()) && !$c->isCommissionMember($this->user())) {
            throw $this->createAccessDeniedException();
        }
        $pack = $inv->offlinePack($c);

        return $this->render('inventory/scan.html.twig', ['c' => $c, 'rooms' => $pack['rooms']]);
    }

    #[Route('/discrepancies/{id}/resolve', name: 'inventory_resolve', methods: ['POST'])]
    #[IsGranted('ROLE_IT')]
    public function resolve(InventoryDiscrepancy $d, Request $r, LocationRepository $locations): Response
    {
        $this->csrf('campaign' . $d->getCampaign()->getId(), $r);
        $override = (int) $r->request->getString('location') ? $locations->find((int) $r->request->getString('location')) : null;
        $this->inventory->resolve($d, $r->request->getString('decision') === 'confirm', $this->user(), trim($r->request->getString('comment')) ?: null, $override);
        $this->em->flush();

        return $this->redirect(($r->headers->get('referer') ?: $this->generateUrl('inventory_show', ['id' => $d->getCampaign()->getId()])) . '#disc');
    }

    #[Route('/{id}/mark', name: 'inventory_mark', methods: ['POST'])]
    #[IsGranted('ROLE_IT')]
    public function mark(InventoryCampaign $c, Request $r, AssetRepository $assets): Response
    {
        $this->csrf('campaign' . $c->getId(), $r);
        $asset = $assets->findByCode($r->request->getString('code')) ?? throw new BusinessException('Актив не найден');
        $type = ScanResult::tryFrom($r->request->getString('type'));
        if (!in_array($type, [ScanResult::NotFound, ScanResult::Damaged, ScanResult::NeedsClarification], true)) {
            throw new BusinessException('Недопустимая отметка');
        }
        $this->inventory->manualDiscrepancy($c, $asset, $type, trim($r->request->getString('comment')) ?: null);
        $this->em->flush();

        return $this->redirectToRoute('inventory_show', ['id' => $c->getId()]);
    }

    /** Член комиссии: комментарий и подтверждение итогов. */
    #[Route('/{id}/confirm', name: 'inventory_confirm', methods: ['POST'])]
    public function confirm(InventoryCampaign $c, Request $r): Response
    {
        $this->csrf('campaign' . $c->getId(), $r);
        if ($c->getStatus() !== CampaignStatus::WithCommission) {
            throw new BusinessException('Подтверждение доступно, когда кампания передана комиссии');
        }
        foreach ($c->getCommission() as $m) {
            if ($m->getUser() === $this->user()) {
                $m->confirm(trim($r->request->getString('comment')) ?: null);
                $this->audit->log('inventory.commission_confirm', ['objectType' => 'inventory_campaign', 'objectId' => (string) $c->getId(), 'objectLabel' => (string) $c, 'comment' => $m->getComment()]);
                $this->em->flush();
                $this->addFlash('success', 'Итоги подтверждены');

                return $this->redirectToRoute('inventory_show', ['id' => $c->getId()]);
            }
        }
        throw $this->createAccessDeniedException('Вы не входите в состав комиссии');
    }

    /** Итоговые документы (п. 12.4). */
    #[Route('/{id}/report', name: 'inventory_report')]
    public function report(InventoryCampaign $c): Response
    {
        return $this->render('inventory/report.html.twig', [
            'c' => $c, 'summary' => $this->inventory->summary($c), 's' => $this->inventory->reportSections($c),
        ]);
    }

    #[Route('/{id}/act', name: 'inventory_act', methods: ['POST'])]
    public function act(InventoryCampaign $c, Request $r, HandoverService $handover): Response
    {
        $this->csrf('campaign' . $c->getId(), $r);
        if (!$this->canManage($c) && !$this->isGranted('ROLE_IT')) {
            throw $this->createAccessDeniedException();
        }
        $doc = $handover->inventoryAct($c, $this->user(), $this->inventory->summary($c), $this->inventory->reportSections($c));

        return $this->redirectToRoute('inventory_act_pdf', ['id' => $doc->getId()]);
    }

    #[Route('/acts/{id}', name: 'inventory_act_pdf')]
    public function actPdf(HandoverDocument $doc, HandoverService $handover): Response
    {
        if ($doc->getType() !== HandoverDocument::TYPE_INVENTORY) {
            throw $this->createNotFoundException();
        }

        return new Response($handover->pdfContent($doc), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => \App\Util\HeaderUtils::disposition($doc->getNumber() . '.pdf', true)]);
    }
}
