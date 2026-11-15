<?php

namespace App\Controller;

use App\Entity\DigitalSignature;
use App\Entity\Employee;
use App\Entity\HandoverDocument;
use App\Entity\User;
use App\Enum\AssetStatus;
use App\Exception\BusinessException;
use App\Repository\AssetRepository;
use App\Repository\EmployeeRepository;
use App\Repository\LocationRepository;
use App\Service\AuditLogger;
use App\Service\HandoverService;
use App\Service\Signature\SignatureVerifier;
use App\Service\SignLinkService;
use App\Util\HeaderUtils;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Выдача, возврат, акты и ЭЦП (п. 13 ТЗ).
 */
#[IsGranted('ROLE_IT')]
class HandoverController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HandoverService $handover,
        private readonly AssetRepository $assets,
    ) {
    }

    /** Шаги 1–2: выбор сотрудника, просмотр уже закрепленного, добавление позиций (сканером или поиском). */
    #[Route('/handover/issue', name: 'handover_issue', methods: ['GET', 'POST'])]
    public function issue(Request $r, EmployeeRepository $employees): Response
    {
        $employee = $r->get('employee') ? $employees->find($r->get('employee')) : null;
        if ($r->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('handover_issue', $r->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            if (!$employee) {
                throw new BusinessException('Выберите сотрудника');
            }
            $items = $this->assets->findBy(['id' => array_values(array_unique($r->request->all('assets')))]);
            /** @var User $it */
            $it = $this->getUser();
            $doc = $this->handover->issue($employee, $items, $it, trim($r->request->getString('comment')) ?: null);
            $this->handover->notify($doc);
            $this->addFlash('success', sprintf('Оформлена выдача, акт %s. Сотруднику отправлено уведомление со ссылкой на подписание.', $doc->getNumber()));

            return $this->redirectToRoute('handover_document', ['id' => $doc->getId()]);
        }

        return $this->render('handover/issue.html.twig', [
            'employee' => $employee,
            'current' => $employee ? $this->assets->findAssignedTo($employee) : [],
            'preselect' => $r->query->get('asset') ? $this->assets->find($r->query->get('asset')) : null,
        ]);
    }

    #[Route('/handover/return', name: 'handover_return', methods: ['GET', 'POST'])]
    public function return(Request $r, EmployeeRepository $employees, LocationRepository $locations): Response
    {
        $employee = $r->get('employee') ? $employees->find($r->get('employee')) : null;
        if ($r->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('handover_return', $r->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            if (!$employee) {
                throw new BusinessException('Выберите сотрудника');
            }
            $items = $this->assets->findBy(['id' => array_values(array_unique($r->request->all('assets')))]);
            $loc = (int) $r->request->getString('location') ? $locations->find((int) $r->request->getString('location')) : null;
            $status = AssetStatus::tryFrom($r->request->getString('status')) ?? AssetStatus::InStock;
            /** @var User $it */
            $it = $this->getUser();
            $doc = $this->handover->return($employee, $items, $it, $loc, $status, trim($r->request->getString('comment')) ?: null);
            $this->handover->notify($doc);
            $this->addFlash('success', sprintf('Оформлен возврат, акт %s', $doc->getNumber()));

            return $this->redirectToRoute('handover_document', ['id' => $doc->getId()]);
        }

        return $this->render('handover/return.html.twig', [
            'employee' => $employee,
            'current' => $employee ? $this->assets->findAssignedTo($employee) : [],
            'locations' => $locations->getFlatTree(true),
            'preselect' => $r->query->get('asset'),
        ]);
    }

    #[Route('/documents', name: 'handover_documents')]
    public function documents(Request $r): Response
    {
        $criteria = [];
        if ($st = $r->query->getString('status')) {
            $criteria['status'] = $st;
        }
        if ($t = $r->query->getString('type')) {
            $criteria['type'] = $t;
        }

        return $this->render('handover/documents.html.twig', [
            'docs' => $this->em->getRepository(HandoverDocument::class)->findBy($criteria, ['createdAt' => 'DESC'], 200),
            'status' => $st, 'type' => $t,
        ]);
    }

    #[Route('/documents/act/{id}', name: 'handover_document')]
    public function document(HandoverDocument $doc, SignLinkService $links, SignatureVerifier $verifier): Response
    {
        return $this->render('handover/document.html.twig', [
            'doc' => $doc,
            'sign_url' => $doc->getEmployee() ? $this->generateUrl('sign_document', ['id' => $doc->getId(), 'token' => $links->token($doc)], UrlGeneratorInterface::ABSOLUTE_URL) : null,
            'sign_token' => $links->token($doc),
            'verify_mode' => $verifier->mode(),
        ]);
    }

    #[Route('/documents/act/{id}/pdf', name: 'handover_pdf')]
    #[IsGranted('ROLE_USER')]
    public function pdf(HandoverDocument $doc, AuditLogger $audit): Response
    {
        $content = $this->handover->pdfContent($doc);
        $audit->log('document.view', ['objectType' => 'handover_document', 'objectId' => $doc->getId()->toRfc4122(), 'objectLabel' => $doc->getNumber()], true);

        return new Response($content, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => HeaderUtils::disposition($doc->getNumber() . '.pdf', true)]);
    }

    #[Route('/documents/act/{id}/resend', name: 'handover_resend', methods: ['POST'])]
    public function resend(HandoverDocument $doc, Request $r): Response
    {
        if (!$this->isCsrfTokenValid('resend' . $doc->getId(), $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->handover->notify($doc);
        $this->addFlash('success', 'Уведомление отправлено повторно');

        return $this->redirectToRoute('handover_document', ['id' => $doc->getId()]);
    }

    /** IT подписывает/принимает подпись с рабочего места сотрудника (NCALayer на этом ПК). */
    #[Route('/documents/act/{id}/sign', name: 'handover_sign', methods: ['POST'])]
    public function sign(HandoverDocument $doc, Request $r): Response
    {
        $this->guardJson($r);
        $sig = $this->handover->attachSignature($doc, (string) ($r->toArray()['cms'] ?? ''), 'web');

        return $this->json(['status' => $doc->getStatus(), 'verification' => $sig->getVerificationStatus(), 'label' => $sig->getVerificationLabel(), 'details' => $sig->getVerificationDetails()['note'] ?? null]);
    }

    #[Route('/documents/signature/{id}/confirm', name: 'handover_signature_confirm', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function confirmSignature(DigitalSignature $sig, Request $r): Response
    {
        if (!$this->isCsrfTokenValid('sigconfirm' . $sig->getId(), $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $comment = trim($r->request->getString('comment'));
        if ($comment === '') {
            throw new BusinessException('Укажите, как проверена подпись (например, «проверено в ezSigner»)');
        }
        /** @var User $u */
        $u = $this->getUser();
        $this->handover->confirmSignatureManually($sig, $u, $comment);
        $this->addFlash('success', 'Подпись подтверждена вручную');

        return $this->redirectToRoute('handover_document', ['id' => $sig->getDocument()->getId()]);
    }

    private function guardJson(Request $r): void
    {
        if (!$r->headers->has('X-Requested-With')) {
            throw $this->createAccessDeniedException();
        }
    }
}
