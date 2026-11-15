<?php

namespace App\Controller;

use App\Entity\HandoverDocument;
use App\Entity\InventoryDiscrepancy;
use App\Entity\License;
use App\Entity\Notification;
use App\Enum\AssetStatus;
use App\Repository\AssetRepository;
use App\Repository\InventoryCampaignRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(AssetRepository $assets, InventoryCampaignRepository $campaigns, EntityManagerInterface $em): Response
    {
        $byStatus = $assets->countByStatus();
        $licensesExpiring = $em->createQuery('SELECT l FROM App\Entity\License l WHERE l.validUntil IS NOT NULL AND l.validUntil < :d AND l.status != :r ORDER BY l.validUntil')
            ->setParameter('d', new \DateTimeImmutable('+30 days'))->setParameter('r', 'revoked')->setMaxResults(10)->getResult();

        return $this->render('dashboard/index.html.twig', [
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
            'statuses' => AssetStatus::cases(),
            'active_campaigns' => $campaigns->findActive(),
            'awaiting_signature' => $em->getRepository(HandoverDocument::class)->findBy(['status' => HandoverDocument::STATUS_AWAITING], ['createdAt' => 'DESC'], 10),
            'open_discrepancies' => $em->getRepository(InventoryDiscrepancy::class)->count(['status' => InventoryDiscrepancy::STATUS_OPEN]),
            'failed_notifications' => $em->getRepository(Notification::class)->count(['status' => Notification::STATUS_FAILED]),
            'licenses_expiring' => $licensesExpiring,
            'licenses_total' => $em->getRepository(License::class)->count([]),
        ]);
    }

    /** Мониторинг доступности (п. 19): БД и очередь. */
    #[Route('/health', name: 'app_health')]
    public function health(Connection $db): JsonResponse
    {
        try {
            $db->fetchOne('SELECT 1');
            $queue = (int) $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'default' AND delivered_at IS NULL");
            $failed = (int) $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'");

            return new JsonResponse(['status' => 'ok', 'db' => 'ok', 'queue_pending' => $queue, 'queue_failed' => $failed]);
        } catch (\Throwable $e) {
            return new JsonResponse(['status' => 'error', 'db' => 'unavailable'], 503);
        }
    }
}
