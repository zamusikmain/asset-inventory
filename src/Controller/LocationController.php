<?php

namespace App\Controller;

use App\Entity\InventoryDiscrepancy;
use App\Entity\Location;
use App\Entity\OfficePlan;
use App\Enum\LocationType;
use App\Enum\RoomType;
use App\Exception\BusinessException;
use App\Repository\AssetRepository;
use App\Repository\LocationRepository;
use App\Service\AuditLogger;
use App\Service\FileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Справочник локаций и планы офисов (п. 15 ТЗ).
 */
#[Route('/locations')]
#[IsGranted('ROLE_USER')]
class LocationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LocationRepository $repo,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'location_index')]
    public function index(AssetRepository $assets): Response
    {
        $tree = $this->repo->getFlatTree();
        $counts = $assets->countByLocationIds(array_map(fn ($t) => $t['node']->getId(), $tree));
        $plans = [];
        foreach ($this->em->getRepository(OfficePlan::class)->findAll() as $p) {
            $plans[$p->getLocation()->getId()][] = $p;
        }

        return $this->render('location/index.html.twig', ['tree' => $tree, 'counts' => $counts, 'plans' => $plans]);
    }

    #[Route('/save', name: 'location_save', methods: ['POST'])]
    #[IsGranted('ROLE_IT')]
    public function save(Request $r): Response
    {
        if (!$this->isCsrfTokenValid('location_save', $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $id = (int) $r->request->getString('id');
        $loc = $id ? ($this->repo->find($id) ?? throw $this->createNotFoundException()) : new Location();
        $before = $id ? ['name' => $loc->getName(), 'type' => $loc->getType()->value, 'parent' => $loc->getParent()?->getPathString(), 'active' => $loc->isActive()] : [];

        $parent = (int) $r->request->getString('parent') ? $this->repo->find((int) $r->request->getString('parent')) : null;
        $type = LocationType::tryFrom($r->request->getString('type')) ?? throw new BusinessException('Укажите тип');
        if ($parent && !in_array($type, $parent->getType()->childTypes(), true)) {
            throw new BusinessException(sprintf('«%s» не может находиться внутри «%s»', $type->label(), $parent->getType()->label()));
        }
        if ($parent && $id && $parent->isDescendantOf($loc)) {
            throw new BusinessException('Нельзя переместить узел внутрь самого себя');
        }
        $name = trim($r->request->getString('name'));
        if ($name === '') {
            throw new BusinessException('Укажите название');
        }
        $loc->setParent($parent)->setType($type)->setName($name)
            ->setCode(trim($r->request->getString('code')) ?: null)
            ->setRoomType($type === LocationType::Room ? (RoomType::tryFrom($r->request->getString('room_type')) ?? RoomType::Cabinet) : null)
            ->setActive(!$r->request->has('inactive'));
        $this->em->persist($loc);
        $this->em->flush();
        $this->audit->log($id ? 'location.update' : 'location.create', ['objectType' => 'location', 'objectId' => (string) $loc->getId(), 'objectLabel' => $loc->getPathString(),
            'old' => $before ?: null, 'new' => ['name' => $loc->getName(), 'type' => $loc->getType()->value, 'parent' => $parent?->getPathString(), 'active' => $loc->isActive()]], true);
        $this->addFlash('success', 'Сохранено');

        return $this->redirectToRoute('location_index', ['_fragment' => 'loc' . $loc->getId()]);
    }

    #[Route('/{id}', name: 'location_show', requirements: ['id' => '\d+'])]
    public function show(Location $loc, AssetRepository $assets): Response
    {
        $ids = $this->repo->getDescendantIds($loc);

        return $this->render('location/show.html.twig', [
            'loc' => $loc,
            'assets' => $assets->registryQuery(['location' => $loc->getId()])->setMaxResults(500)->getQuery()->getResult(),
            'plans' => $this->em->getRepository(OfficePlan::class)->findBy(['location' => $loc]),
            'children' => $loc->getChildren(),
            'total' => array_sum($assets->countByLocationIds($ids)),
        ]);
    }

    /** Удаление пустой локации: без вложенных узлов и без активов. Планы удаляются вместе с файлами. */
    #[Route('/{id}/delete', name: 'location_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Location $loc, Request $r, FileStorage $storage): Response
    {
        if (!$this->isCsrfTokenValid('location_delete' . $loc->getId(), $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$loc->getChildren()->isEmpty()) {
            throw new BusinessException(sprintf('Нельзя удалить «%s»: сначала удалите вложенные локации (%d)', $loc->getName(), $loc->getChildren()->count()));
        }
        $assets = (int) $this->em->createQuery('SELECT COUNT(a.id) FROM App\Entity\Asset a WHERE a.location = :l')->setParameter('l', $loc)->getSingleScalarResult();
        if ($assets > 0) {
            throw new BusinessException(sprintf('Нельзя удалить «%s»: в ней %d активов (включая архивные). Сначала переместите их.', $loc->getName(), $assets));
        }
        $parent = $loc->getParent();
        foreach ($this->em->getRepository(OfficePlan::class)->findBy(['location' => $loc]) as $plan) {
            $storage->delete($plan->getStoragePath());
            $this->em->remove($plan);
        }
        $this->audit->log('location.delete', ['objectType' => 'location', 'objectId' => (string) $loc->getId(), 'objectLabel' => $loc->getPathString(),
            'old' => ['name' => $loc->getName(), 'type' => $loc->getType()->value, 'code' => $loc->getCode()]]);
        $this->em->remove($loc);
        $this->em->flush();
        $this->addFlash('success', 'Локация удалена');

        return $parent ? $this->redirectToRoute('location_show', ['id' => $parent->getId()]) : $this->redirectToRoute('location_index');
    }

    // ------------------------------------------------------------------ планы

    #[Route('/{id}/plans', name: 'plan_upload', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_IT')]
    public function uploadPlan(Location $loc, Request $r, FileStorage $storage): Response
    {
        if (!$this->isCsrfTokenValid('plan_upload' . $loc->getId(), $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $file = $r->files->get('file') ?? throw new BusinessException('Выберите файл SVG или PNG');
        $s = $storage->storeUpload($file, 'plans', FileStorage::PLAN_TYPES);
        [$w, $h] = [0, 0];
        if ($s['ext'] === 'png') {
            [$w, $h] = getimagesize($storage->absolute($s['path'])) ?: [0, 0];
            if (max($w, $h) < 2000) {
                $storage->delete($s['path']);
                throw new BusinessException(sprintf('PNG должен быть не менее 2000 px по длинной стороне (загружено %d×%d). Предпочтительный формат — SVG.', $w, $h));
            }
        } else {
            $svg = $storage->read($s['path']);
            if (preg_match('/viewBox\s*=\s*"[\d.\-]+[\s,]+[\d.\-]+[\s,]+([\d.]+)[\s,]+([\d.]+)"/i', $svg, $m)) {
                [$w, $h] = [(int) $m[1], (int) $m[2]];
            } elseif (preg_match('/width\s*=\s*"([\d.]+)/i', $svg, $mw) && preg_match('/height\s*=\s*"([\d.]+)/i', $svg, $mh)) {
                [$w, $h] = [(int) $mw[1], (int) $mh[1]];
            }
        }
        $plan = new OfficePlan($loc, trim($r->request->getString('name')) ?: $loc->getName(), $s['path'], $s['ext'] === 'svg' ? 'image/svg+xml' : 'image/png');
        $plan->setDimensions($w ?: 1000, $h ?: 700);
        $this->em->persist($plan);
        $this->em->flush();
        $this->audit->log('plan.upload', ['objectType' => 'office_plan', 'objectId' => (string) $plan->getId(), 'objectLabel' => $loc->getPathString()], true);

        return $this->redirectToRoute('plan_show', ['id' => $plan->getId()]);
    }

    /**
     * План: помещения, количество активов и расхождений; список открывается по клику.
     */
    #[Route('/plans/{id}', name: 'plan_show')]
    public function plan(OfficePlan $plan, AssetRepository $assets): Response
    {
        $roomIds = array_values(array_filter(array_map(fn ($r) => (int) ($r['locationId'] ?? 0), $plan->getRegions())));
        $counts = [];
        $disc = [];
        foreach ($roomIds as $rid) {
            $room = $this->repo->find($rid);
            if (!$room) {
                continue;
            }
            $ids = $this->repo->getDescendantIds($room);
            $counts[$rid] = array_sum($assets->countByLocationIds($ids));
            $disc[$rid] = (int) $this->em->createQuery('SELECT COUNT(d.id) FROM App\Entity\InventoryDiscrepancy d WHERE d.status = :o AND (IDENTITY(d.expectedLocation) IN (:ids) OR IDENTITY(d.actualLocation) IN (:ids))')
                ->setParameter('o', InventoryDiscrepancy::STATUS_OPEN)->setParameter('ids', $ids)->getSingleScalarResult();
        }
        $rooms = $this->repo->getDescendantIds($plan->getLocation());

        return $this->render('location/plan.html.twig', [
            'plan' => $plan,
            'counts' => $counts,
            'disc' => $disc,
            'rooms' => array_values(array_filter($this->repo->findBy(['id' => $rooms], ['name' => 'ASC']), fn (Location $l) => $l->getType() === LocationType::Room)),
            'names' => array_combine($roomIds, array_map(fn ($id) => $this->repo->find($id)?->getName() ?? '?', $roomIds)) ?: [],
        ]);
    }

    #[Route('/plans/{id}/image', name: 'plan_image')]
    public function planImage(OfficePlan $plan, FileStorage $storage): Response
    {
        return new Response($storage->read($plan->getStoragePath()), 200, [
            'Content-Type' => $plan->getMimeType(),
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** Связь областей плана с помещениями редактирует только IT. */
    #[Route('/plans/{id}/regions', name: 'plan_regions', methods: ['POST'])]
    #[IsGranted('ROLE_IT')]
    public function regions(OfficePlan $plan, Request $r): Response
    {
        if (!$r->headers->has('X-Requested-With')) {
            throw $this->createAccessDeniedException();
        }
        $clean = [];
        foreach ($r->toArray()['regions'] ?? [] as $reg) {
            $lid = (int) ($reg['locationId'] ?? 0);
            if (!$lid || !$this->repo->find($lid)) {
                continue;
            }
            $clean[] = [
                'locationId' => $lid,
                'x' => round(max(0, min(100, (float) $reg['x'])), 3), 'y' => round(max(0, min(100, (float) $reg['y'])), 3),
                'w' => round(max(0.1, min(100, (float) $reg['w'])), 3), 'h' => round(max(0.1, min(100, (float) $reg['h'])), 3),
            ];
        }
        $old = $plan->getRegions();
        $plan->setRegions($clean);
        $this->audit->log('plan.regions', ['objectType' => 'office_plan', 'objectId' => (string) $plan->getId(), 'objectLabel' => $plan->getName(),
            'old' => ['regions' => count($old)], 'new' => ['regions' => count($clean)]]);
        $this->em->flush();

        return $this->json(['ok' => true, 'count' => count($clean)]);
    }

    #[Route('/plans/{id}/delete', name: 'plan_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deletePlan(OfficePlan $plan, Request $r, FileStorage $storage): Response
    {
        if (!$this->isCsrfTokenValid('plan_delete' . $plan->getId(), $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $loc = $plan->getLocation();
        $this->audit->log('plan.delete', ['objectType' => 'office_plan', 'objectId' => (string) $plan->getId(), 'objectLabel' => $plan->getName()]);
        $storage->delete($plan->getStoragePath());
        $this->em->remove($plan);
        $this->em->flush();

        return $this->redirectToRoute('location_show', ['id' => $loc->getId()]);
    }
}
