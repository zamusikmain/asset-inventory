<?php

namespace App\Controller;

use App\Entity\Asset;
use App\Entity\InventoryCampaign;
use App\Entity\InventoryScan;
use App\Entity\Location;
use App\Entity\User;
use App\Enum\ScanResult;
use App\Exception\BusinessException;
use App\Repository\AssetRepository;
use App\Repository\EmployeeRepository;
use App\Repository\InventoryCampaignRepository;
use App\Service\InventoryService;
use App\Service\Integration\IntegrationOutbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * REST API (п. 3 ТЗ) для USB-сканирования, PWA и будущих интеграций.
 * Аутентификация — сессия; изменяющие запросы требуют заголовок X-Requested-With
 * (защита от CSRF: кросс-доменная форма не может установить заголовок).
 */
#[Route('/api/v1')]
#[IsGranted('ROLE_USER')]
class ApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssetRepository $assets,
    ) {
    }

    private function guard(Request $r): void
    {
        if (!$r->headers->has('X-Requested-With')) {
            throw $this->createAccessDeniedException('Требуется заголовок X-Requested-With');
        }
    }

    public static function assetJson(Asset $a): array
    {
        return [
            'id' => $a->getId()->toRfc4122(),
            'inventory_number' => $a->getInventoryNumber(),
            'full_inventory_number' => $a->getFullInventoryNumber(),
            'barcode' => $a->getBarcode(),
            'name' => $a->getName(),
            'type' => $a->getType()?->__toString(),
            'manufacturer' => $a->getManufacturer(),
            'model' => $a->getModel(),
            'serial_number' => $a->getSerialNumber(),
            'status' => $a->getStatus()->value,
            'status_label' => $a->getStatus()->label(),
            'employee' => $a->getEmployee() ? ['id' => $a->getEmployee()->getId()->toRfc4122(), 'name' => $a->getEmployee()->getName()] : null,
            'location' => $a->getLocation() ? ['id' => $a->getLocation()->getId(), 'path' => $a->getLocation()->getPathString()] : null,
            'bundle' => $a->getBundle()?->getName(),
            'archived' => $a->isArchived(),
            'external_1c_id' => $a->getExternal1cId(),
            'updated_at' => $a->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /** Поиск по коду сканера (п. 10.1): найден / не найден / списан / некорректный код. */
    #[Route('/lookup', name: 'api_lookup', methods: ['GET'])]
    public function lookup(Request $r): JsonResponse
    {
        $raw = $r->query->getString('code');
        $code = Asset::normalizeCode($raw);
        if ($code === '' || !preg_match('/^[\x20-\x7E]{1,64}$/', $code)) {
            return $this->json(['result' => 'invalid_code', 'message' => 'Некорректный код', 'code' => $raw]);
        }
        $a = $this->assets->findByCode($code);
        if (!$a) {
            return $this->json(['result' => 'not_found', 'message' => 'Актив не найден', 'code' => $code]);
        }
        $result = ($a->getStatus()->isTerminal() || $a->isArchived()) ? 'written_off' : 'found';

        return $this->json([
            'result' => $result,
            'message' => $result === 'found' ? 'Найден' : 'Актив списан / в архиве',
            'asset' => self::assetJson($a),
            'url' => $this->generateUrl('asset_show', ['id' => $a->getId()]),
        ]);
    }

    #[Route('/assets', name: 'api_assets', methods: ['GET'])]
    public function assets(Request $r): JsonResponse
    {
        $qb = $this->assets->registryQuery(['q' => $r->query->getString('q'), 'status' => $r->query->getString('status'), 'employee' => $r->query->getString('employee')])
            ->setMaxResults(min(100, max(1, $r->query->getInt('limit', 20))));

        return $this->json(['items' => array_map([self::class, 'assetJson'], $qb->getQuery()->getResult())]);
    }

    #[Route('/assets/{code}', name: 'api_asset', methods: ['GET'], requirements: ['code' => '[\x21-\x7E]{1,64}'])]
    public function asset(string $code): JsonResponse
    {
        $a = $this->assets->findByCode($code) ?? throw $this->createNotFoundException();

        return $this->json(self::assetJson($a));
    }

    #[Route('/employees', name: 'api_employees', methods: ['GET'])]
    public function employees(Request $r, EmployeeRepository $repo): JsonResponse
    {
        $list = $repo->searchQuery($r->query->getString('q'), 'active')->setMaxResults(20)->getQuery()->getResult();

        return $this->json(['items' => array_map(fn ($e) => [
            'id' => $e->getId()->toRfc4122(), 'name' => $e->getName(), 'display_name' => $e->getDisplayName(),
            'position' => $e->getPosition(), 'department' => $e->getDepartment()?->getName(), 'email' => $e->getEmail(),
        ], $list)]);
    }

    // ------------------------------------------------------------------ инвентаризация

    #[Route('/inventory/campaigns', name: 'api_campaigns', methods: ['GET'])]
    public function campaigns(InventoryCampaignRepository $repo): JsonResponse
    {
        return $this->json(['items' => array_map(fn (InventoryCampaign $c) => [
            'id' => $c->getId(), 'number' => $c->getNumber(), 'name' => $c->getName(), 'status' => $c->getStatus()->value, 'status_label' => $c->getStatus()->label(),
        ], $repo->findActive())]);
    }

    #[Route('/inventory/{id}/pack', name: 'api_campaign_pack', methods: ['GET'])]
    public function pack(InventoryCampaign $c, InventoryService $inv): JsonResponse
    {
        $this->denyUnlessScanner($c);

        return $this->json($inv->offlinePack($c));
    }

    /** Онлайн-скан (USB-сканер на странице кампании). */
    #[Route('/inventory/{id}/scan', name: 'api_campaign_scan', methods: ['POST'])]
    public function scan(InventoryCampaign $c, Request $r, InventoryService $inv): JsonResponse
    {
        $this->guard($r);
        $this->denyUnlessScanner($c);
        $d = $r->toArray();
        $loc = !empty($d['location_id']) ? $this->em->find(Location::class, (int) $d['location_id']) : null;
        $opId = isset($d['op_id']) && Uuid::isValid($d['op_id']) ? Uuid::fromString($d['op_id']) : null;
        /** @var User $user */
        $user = $this->getUser();
        $scan = $inv->scan($c, (string) ($d['code'] ?? ''), $user, $opId, [
            'location' => $loc, 'source' => in_array($d['source'] ?? 'usb', ['usb', 'camera', 'manual'], true) ? $d['source'] : 'usb',
            'flag' => in_array($d['flag'] ?? null, ['damaged', 'label_damaged', 'clarify'], true) ? $d['flag'] : null,
            'comment' => isset($d['comment']) ? mb_substr((string) $d['comment'], 0, 1000) : null,
        ]);

        return $this->json($this->scanJson($scan));
    }

    /**
     * Синхронизация offline-операций PWA (п. 11). Каждая операция имеет UUID:
     * повторная отправка не создает дубль, конфликты сохраняются для ручной проверки.
     */
    #[Route('/inventory/sync', name: 'api_inventory_sync', methods: ['POST'])]
    public function sync(Request $r, InventoryService $inv): JsonResponse
    {
        $this->guard($r);
        $d = $r->toArray();
        /** @var User $user */
        $user = $this->getUser();
        $results = [];
        foreach (array_slice($d['ops'] ?? [], 0, 500) as $op) {
            $opId = (string) ($op['op_id'] ?? '');
            try {
                if (!Uuid::isValid($opId)) {
                    throw new BusinessException('Некорректный UUID операции');
                }
                $c = $this->em->find(InventoryCampaign::class, (int) ($op['campaign_id'] ?? 0)) ?? throw new BusinessException('Кампания не найдена');
                $this->denyUnlessScanner($c);
                $loc = !empty($op['location_id']) ? $this->em->find(Location::class, (int) $op['location_id']) : null;
                $scannedAt = null;
                if (!empty($op['scanned_at'])) {
                    try {
                        $scannedAt = new \DateTimeImmutable($op['scanned_at']);
                    } catch (\Exception) {
                    }
                }
                $scan = $inv->scan($c, (string) ($op['code'] ?? ''), $user, Uuid::fromString($opId), [
                    'location' => $loc,
                    'source' => in_array($op['source'] ?? 'camera', ['usb', 'camera', 'manual'], true) ? $op['source'] : 'camera',
                    'scannedAt' => $scannedAt,
                    'flag' => in_array($op['flag'] ?? null, ['damaged', 'label_damaged', 'clarify'], true) ? $op['flag'] : null,
                    'comment' => isset($op['comment']) ? mb_substr((string) $op['comment'], 0, 1000) : null,
                    'deviceId' => isset($d['device_id']) ? mb_substr((string) $d['device_id'], 0, 64) : null,
                    'expectedLocationId' => array_key_exists('expected_location_id', $op) ? ($op['expected_location_id'] !== null ? (int) $op['expected_location_id'] : null) : false,
                ]);
                $results[] = ['op_id' => $opId] + $this->scanJson($scan);
            } catch (BusinessException $e) {
                $results[] = ['op_id' => $opId, 'sync_status' => 'error', 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                if (!$this->em->isOpen()) {
                    // EntityManager закрыт после ошибки БД — оставшиеся операции клиент отправит повторно
                    $results[] = ['op_id' => $opId, 'sync_status' => 'error', 'message' => 'Ошибка сервера, повторите синхронизацию'];
                    break;
                }
                $results[] = ['op_id' => $opId, 'sync_status' => 'error', 'message' => 'Ошибка обработки: ' . $e->getMessage()];
            }
        }

        return $this->json(['results' => $results, 'server_time' => (new \DateTimeImmutable())->format(DATE_ATOM)]);
    }

    private function scanJson(InventoryScan $s): array
    {
        $first = $s->getDuplicateOf();
        $message = $s->getResult()->label();
        if ($first) {
            $message = sprintf('Уже отсканирован %s, %s', $first->getScannedAt()->setTimezone(new \DateTimeZone('Asia/Almaty'))->format('d.m.Y H:i:s'), $first->getScannedByName() ?? '—');
        }

        return [
            'scan_id' => $s->getId()->toRfc4122(),
            'sync_status' => $s->getSyncStatus(),
            'result' => $s->getResult()->value,
            'result_label' => $s->getResult()->label(),
            'color' => $s->getResult()->color(),
            'message' => $message,
            'code' => $s->getRawCode(),
            'asset' => $s->getAsset() ? self::assetJson($s->getAsset()) : null,
            'expected_location' => $s->getExpectedLocation()?->getPathString(),
            'actual_location' => $s->getLocation()?->getPathString(),
            'duplicate_of' => $first ? ['at' => $first->getScannedAt()->format(DATE_ATOM), 'by' => $first->getScannedByName()] : null,
            'conflict' => $s->getSyncStatus() === InventoryScan::SYNC_CONFLICT,
        ];
    }

    private function denyUnlessScanner(InventoryCampaign $c): void
    {
        $u = $this->getUser();
        if ($this->isGranted('ROLE_IT') || ($u instanceof User && ($c->getItResponsible()->contains($u) || $c->isCommissionMember($u)))) {
            return;
        }
        throw $this->createAccessDeniedException('Нет доступа к сканированию в этой кампании');
    }

    // ------------------------------------------------------------------ интеграции (подготовка, п. 17)

    /** Выгрузка изменений для 1С (pull-модель). Отключаемо. */
    #[Route('/integration/1c/assets', name: 'api_1c_assets', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function oneCAssets(Request $r, IntegrationOutbox $outbox): JsonResponse
    {
        if (!$outbox->isEnabled()) {
            return $this->json(['error' => 'integration_disabled'], 503);
        }
        $since = $r->query->getString('since');
        $qb = $this->assets->createQueryBuilder('a')->orderBy('a.updatedAt', 'ASC')->setMaxResults(500);
        if ($since) {
            $qb->where('a.updatedAt > :s')->setParameter('s', new \DateTimeImmutable($since));
        }

        return $this->json(['items' => array_map([self::class, 'assetJson'], $qb->getQuery()->getResult())]);
    }
}
