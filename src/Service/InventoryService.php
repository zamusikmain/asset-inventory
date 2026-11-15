<?php

namespace App\Service;

use App\Entity\Asset;
use App\Entity\InventoryCampaign;
use App\Entity\InventoryDiscrepancy;
use App\Entity\InventoryScan;
use App\Entity\Location;
use App\Entity\User;
use App\Enum\AssetStatus;
use App\Enum\CampaignStatus;
use App\Enum\LocationType;
use App\Enum\ScanResult;
use App\Exception\BusinessException;
use App\Repository\AssetRepository;
use App\Service\Integration\IntegrationOutbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Инвентаризация (п. 10.1, 11, 12 ТЗ): кампании, сканирование, расхождения, итоги.
 */
class InventoryService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssetRepository $assets,
        private readonly AssetService $assetService,
        private readonly AuditLogger $audit,
        private readonly IntegrationOutbox $outbox,
    ) {
    }

    // ---------------------------------------------------------------- кампания

    public function transition(InventoryCampaign $c, CampaignStatus $to, User $by, ?string $comment = null): void
    {
        $from = $c->getStatus();
        if (!$from->canTransitionTo($to)) {
            throw new BusinessException(sprintf('Переход кампании «%s» → «%s» недопустим', $from->label(), $to->label()));
        }

        if ($to === CampaignStatus::Started) {
            if ($c->getItResponsible()->isEmpty()) {
                throw new BusinessException('Назначьте ответственных IT перед запуском');
            }
            $snapshot = [];
            foreach ($this->assets->findForCampaign($c) as $a) {
                $snapshot[$a->getId()->toRfc4122()] = $a->getLocation()?->getId();
            }
            $c->setExpectedSnapshot($snapshot);
            $c->setStartedAt(new \DateTimeImmutable());
        }
        if ($to === CampaignStatus::AwaitingReview) {
            $this->generateNotFound($c);
        }
        if ($to === CampaignStatus::Approved) {
            $open = $this->em->getRepository(InventoryDiscrepancy::class)->count(['campaign' => $c, 'status' => InventoryDiscrepancy::STATUS_OPEN]);
            if ($open > 0) {
                throw new BusinessException(sprintf('Есть нерешенные расхождения: %d. Подтвердите или отклоните их до утверждения.', $open));
            }
            if ($c->getCommission()->count() > 0) {
                foreach ($c->getCommission() as $m) {
                    if (!$m->getConfirmedAt()) {
                        throw new BusinessException('Не все члены комиссии подтвердили итоги');
                    }
                }
            }
            $c->setApprovedAt(new \DateTimeImmutable());
            $this->outbox->campaignApproved($c, $this->summary($c));
        }
        if ($to === CampaignStatus::Closed) {
            $c->setClosedAt(new \DateTimeImmutable());
        }

        $c->setStatus($to);
        $this->audit->log('inventory.campaign_status', [
            'objectType' => 'inventory_campaign', 'objectId' => (string) $c->getId(), 'objectLabel' => (string) $c,
            'old' => ['status' => $from->value], 'new' => ['status' => $to->value], 'comment' => $comment,
        ]);
    }

    /** Для ожидаемых, но не отсканированных активов создаются расхождения «Не найден». */
    private function generateNotFound(InventoryCampaign $c): void
    {
        $scanned = $this->em->createQuery(
            'SELECT DISTINCT IDENTITY(s.asset) FROM App\Entity\InventoryScan s WHERE s.campaign = :c AND s.asset IS NOT NULL'
        )->setParameter('c', $c)->getSingleColumnResult();
        $scanned = array_flip(array_map(fn ($id) => $id instanceof Uuid ? $id->toRfc4122() : (string) $id, $scanned));

        $already = $this->em->createQuery(
            'SELECT IDENTITY(d.asset) FROM App\Entity\InventoryDiscrepancy d WHERE d.campaign = :c AND d.type = :t AND d.asset IS NOT NULL'
        )->setParameter('c', $c)->setParameter('t', ScanResult::NotFound)->getSingleColumnResult();
        $already = array_flip(array_map(fn ($id) => $id instanceof Uuid ? $id->toRfc4122() : (string) $id, $already));

        foreach ($c->getExpectedSnapshot() as $assetId => $locId) {
            if (isset($scanned[$assetId]) || isset($already[$assetId])) {
                continue;
            }
            $asset = $this->assets->find($assetId);
            if (!$asset) {
                continue;
            }
            $d = (new InventoryDiscrepancy($c, ScanResult::NotFound))
                ->setAsset($asset)
                ->setRawCode($asset->getInventoryNumber())
                ->setExpectedLocation($locId ? $this->em->getReference(Location::class, $locId) : null);
            $this->em->persist($d);
        }
    }

    // ---------------------------------------------------------------- сканирование

    /**
     * Обработка одного скана (USB или PWA). Идемпотентно по $opId.
     *
     * @param array{location?: ?Location, source?: string, scannedAt?: ?\DateTimeImmutable, flag?: ?string,
     *              comment?: ?string, deviceId?: ?string, expectedLocationId?: int|null|false} $opt
     *        expectedLocationId — что видел offline-клиент; false = не передавалось
     */
    public function scan(InventoryCampaign $c, string $rawCode, User $by, ?Uuid $opId = null, array $opt = []): InventoryScan
    {
        $opId ??= Uuid::v7();
        $existing = $this->em->find(InventoryScan::class, $opId);
        if ($existing) {
            return $existing; // повторная отправка той же операции — без дубля
        }

        if (!$c->getStatus()->acceptsScans() && ($opt['source'] ?? 'usb') === 'usb') {
            throw new BusinessException(sprintf('Кампания в статусе «%s» — сканирование не принимается', $c->getStatus()->label()));
        }

        $conn = $this->em->getConnection();

        return $this->em->wrapInTransaction(function () use ($c, $rawCode, $by, $opId, $opt, $conn) {
            $code = Asset::normalizeCode($rawCode);
            // сериализация сканов одного кода внутри кампании — защита от гонки двух сканеров
            $conn->executeStatement('SELECT pg_advisory_xact_lock(:k1, hashtext(:k2))', ['k1' => (int) $c->getId(), 'k2' => $code]);

            $location = $opt['location'] ?? null;
            $flag = $opt['flag'] ?? null;
            $scannedAt = $opt['scannedAt'] ?? new \DateTimeImmutable();
            $syncStatus = InventoryScan::SYNC_SYNCED;

            $asset = null;
            $duplicateOf = null;
            $result = null;

            if ($code === '' || mb_strlen($code) > 64 || !preg_match('/^[\x20-\x7E]+$/', $code)) {
                $result = ScanResult::InvalidCode;
            } else {
                $asset = $this->assets->findByCode($code);
                if (!$asset) {
                    $result = $flag === 'label_damaged' ? ScanResult::LabelDamaged : ScanResult::Unknown;
                }
            }

            if ($asset) {
                $duplicateOf = $this->em->createQueryBuilder()
                    ->select('s')->from(InventoryScan::class, 's')
                    ->where('s.campaign = :c AND s.asset = :a AND s.duplicateOf IS NULL AND s.result != :dup')
                    ->setParameter('c', $c)->setParameter('a', $asset)->setParameter('dup', ScanResult::Duplicate)
                    ->orderBy('s.scannedAt', 'ASC')->setMaxResults(1)
                    ->getQuery()->getOneOrNullResult();

                if ($duplicateOf) {
                    $result = ScanResult::Duplicate;
                } elseif ($asset->getStatus()->isTerminal() || $asset->isArchived()) {
                    $result = ScanResult::WrittenOffFound;
                } elseif ($flag === 'damaged') {
                    $result = ScanResult::Damaged;
                } elseif ($flag === 'label_damaged') {
                    $result = ScanResult::LabelDamaged;
                } elseif ($flag === 'clarify') {
                    $result = ScanResult::NeedsClarification;
                } else {
                    $result = $this->compareLocation($asset->getLocation(), $location);
                }

                // Конфликт offline: актив перемещен на сервере после выгрузки пакета
                if (($opt['expectedLocationId'] ?? false) !== false && $result !== ScanResult::Duplicate) {
                    $seen = $opt['expectedLocationId'];
                    if ($seen !== $asset->getLocation()?->getId()) {
                        $syncStatus = InventoryScan::SYNC_CONFLICT;
                        $result = ScanResult::NeedsClarification;
                    }
                }
            }

            if (!$c->getStatus()->acceptsScans()) {
                // offline-скан пришел после закрытия сканирования — сохраняем как конфликт
                $syncStatus = InventoryScan::SYNC_CONFLICT;
            }

            $scan = (new InventoryScan($opId, $c, $code, $result, $scannedAt))
                ->setAsset($asset)
                ->setLocation($location)
                ->setExpectedLocation($asset?->getLocation())
                ->setScannedBy($by)
                ->setSource($opt['source'] ?? 'usb')
                ->setDeviceId($opt['deviceId'] ?? null)
                ->setComment($opt['comment'] ?? null)
                ->setFlag($flag)
                ->setSyncStatus($syncStatus)
                ->setDuplicateOf($duplicateOf);
            $this->em->persist($scan);

            if ($result->createsDiscrepancy() || $syncStatus === InventoryScan::SYNC_CONFLICT) {
                $d = (new InventoryDiscrepancy($c, $result))
                    ->setAsset($asset)
                    ->setScan($scan)
                    ->setRawCode($code)
                    ->setExpectedLocation($asset?->getLocation())
                    ->setActualLocation($location)
                    ->setComment($syncStatus === InventoryScan::SYNC_CONFLICT
                        ? 'Конфликт синхронизации: данные изменились после выгрузки offline-пакета либо сканирование уже закрыто'
                        : ($opt['comment'] ?? null));
                $this->em->persist($d);
            }

            if ($c->getStatus() === CampaignStatus::Started) {
                $c->setStatus(CampaignStatus::InProgress);
            }

            $this->em->flush();

            return $scan;
        });
    }

    public function compareLocation(?Location $expected, ?Location $actual): ScanResult
    {
        if ($actual === null) {
            return ScanResult::FoundExpected; // сканирование без выбора помещения — проверка наличия
        }
        if ($expected === null) {
            return ScanResult::NeedsClarification;
        }
        if ($expected === $actual || $expected->getId() === $actual->getId()) {
            return ScanResult::FoundExpected;
        }
        $eo = $expected->getAncestorOfType(LocationType::Office);
        $ao = $actual->getAncestorOfType(LocationType::Office);
        if ($eo && $ao && $eo->getId() !== $ao->getId()) {
            return ScanResult::FoundOtherOffice;
        }

        return ScanResult::FoundOtherRoom;
    }

    /** Ручная отметка «не найден»/«поврежден» без скана — тоже расхождение. */
    public function manualDiscrepancy(InventoryCampaign $c, Asset $asset, ScanResult $type, ?string $comment): InventoryDiscrepancy
    {
        $d = (new InventoryDiscrepancy($c, $type))->setAsset($asset)->setRawCode($asset->getInventoryNumber())
            ->setExpectedLocation($asset->getLocation())->setComment($comment);
        $this->em->persist($d);
        $this->audit->log('inventory.manual_mark', [
            'objectType' => 'asset', 'objectId' => $asset->getId()->toRfc4122(), 'objectLabel' => (string) $asset,
            'new' => ['result' => $type->value], 'comment' => $comment,
        ]);

        return $d;
    }

    // ---------------------------------------------------------------- расхождения

    /**
     * Решение IT по расхождению (п. 12.3). Только после подтверждения меняется
     * текущее местоположение/статус актива; история сохраняется.
     */
    public function resolve(InventoryDiscrepancy $d, bool $confirm, User $by, ?string $comment, ?Location $overrideLocation = null): void
    {
        if (!$d->isOpen()) {
            throw new BusinessException('Расхождение уже обработано');
        }
        $c = $d->getCampaign();
        if (in_array($c->getStatus(), [CampaignStatus::Approved, CampaignStatus::Closed, CampaignStatus::Cancelled], true)) {
            throw new BusinessException('Кампания закрыта для изменений');
        }
        $asset = $d->getAsset();
        $note = sprintf('Инвентаризация %s: %s', $c->getNumber(), $d->getType()->label());

        if ($confirm && $asset) {
            switch ($d->getType()) {
                case ScanResult::FoundOtherRoom:
                case ScanResult::FoundOtherOffice:
                case ScanResult::NeedsClarification:
                    $target = $overrideLocation ?? $d->getActualLocation();
                    if ($target) {
                        $this->assetService->move($asset, $target, $note . ($comment ? '. ' . $comment : ''), 'inventory');
                    }
                    if ($asset->getStatus() === AssetStatus::NotFound) {
                        $this->assetService->changeStatus($asset, AssetStatus::InStock, $note, 'inventory');
                    }
                    break;
                case ScanResult::NotFound:
                    if ($asset->getStatus()->canTransitionTo(AssetStatus::NotFound)) {
                        $this->assetService->changeStatus($asset, AssetStatus::NotFound, $note, 'inventory');
                    }
                    break;
                case ScanResult::Damaged:
                    if ($asset->getStatus()->canTransitionTo(AssetStatus::Damaged)) {
                        $this->assetService->changeStatus($asset, AssetStatus::Damaged, $note, 'inventory');
                    }
                    break;
                default:
                    // LabelDamaged, WrittenOffFound, Unknown — фиксируются в отчете, данные актива не меняются
                    break;
            }
        }

        $d->resolve($confirm, $by, $comment);
        $this->audit->log('inventory.discrepancy_resolve', [
            'objectType' => 'inventory_discrepancy', 'objectId' => (string) $d->getId(),
            'objectLabel' => $note . ' — ' . ($asset?->getInventoryNumber() ?? $d->getRawCode()),
            'new' => ['decision' => $confirm ? 'confirmed' : 'rejected', 'location' => $overrideLocation?->getPathString()],
            'comment' => $comment,
        ]);
    }

    // ---------------------------------------------------------------- отчеты

    public function summary(InventoryCampaign $c): array
    {
        $byResult = [];
        $rows = $this->em->createQuery('SELECT s.result AS r, COUNT(s.id) AS n FROM App\Entity\InventoryScan s WHERE s.campaign = :c GROUP BY s.result')
            ->setParameter('c', $c)->getArrayResult();
        foreach ($rows as $r) {
            $byResult[$r['r'] instanceof ScanResult ? $r['r']->value : $r['r']] = (int) $r['n'];
        }
        $expected = count($c->getExpectedSnapshot());
        $foundDistinct = (int) $this->em->createQuery('SELECT COUNT(DISTINCT s.asset) FROM App\Entity\InventoryScan s WHERE s.campaign = :c AND s.asset IS NOT NULL')
            ->setParameter('c', $c)->getSingleScalarResult();
        $disc = $this->em->createQuery('SELECT d.status AS st, COUNT(d.id) AS n FROM App\Entity\InventoryDiscrepancy d WHERE d.campaign = :c GROUP BY d.status')
            ->setParameter('c', $c)->getArrayResult();
        $discByStatus = array_column($disc, 'n', 'st');
        $conflicts = (int) $this->em->getRepository(InventoryScan::class)->count(['campaign' => $c, 'syncStatus' => InventoryScan::SYNC_CONFLICT]);

        return [
            'expected' => $expected,
            'found' => $foundDistinct,
            'progress' => $expected ? round(min(100, $foundDistinct * 100 / $expected), 1) : 0,
            'scans_total' => array_sum($byResult),
            'by_result' => $byResult,
            'discrepancies_open' => (int) ($discByStatus['open'] ?? 0),
            'discrepancies_confirmed' => (int) ($discByStatus['confirmed'] ?? 0),
            'discrepancies_rejected' => (int) ($discByStatus['rejected'] ?? 0),
            'conflicts' => $conflicts,
        ];
    }

    /**
     * Разделы итогового отчета (п. 12.4).
     *
     * @return array<string, list<InventoryDiscrepancy|InventoryScan>>
     */
    public function reportSections(InventoryCampaign $c): array
    {
        $scans = $this->em->getRepository(InventoryScan::class)->findBy(['campaign' => $c], ['scannedAt' => 'ASC']);
        $disc = $this->em->getRepository(InventoryDiscrepancy::class)->findBy(['campaign' => $c], ['id' => 'ASC']);

        $found = array_values(array_filter($scans, fn (InventoryScan $s) => $s->getAsset() && $s->getResult() !== ScanResult::Duplicate));
        $pick = fn (array $types) => array_values(array_filter($disc, fn (InventoryDiscrepancy $d) => in_array($d->getType(), $types, true)));

        return [
            'found' => $found,
            'missing' => $pick([ScanResult::NotFound]),
            'unknown' => $pick([ScanResult::Unknown]),
            'other_place' => $pick([ScanResult::FoundOtherRoom, ScanResult::FoundOtherOffice]),
            'label_damaged' => $pick([ScanResult::LabelDamaged]),
            'damaged' => $pick([ScanResult::Damaged]),
            'written_off' => $pick([ScanResult::WrittenOffFound]),
            'clarify' => $pick([ScanResult::NeedsClarification]),
            'location_changes' => array_values(array_filter($disc, fn (InventoryDiscrepancy $d) => $d->getStatus() === InventoryDiscrepancy::STATUS_CONFIRMED
                && in_array($d->getType(), [ScanResult::FoundOtherRoom, ScanResult::FoundOtherOffice, ScanResult::NeedsClarification], true))),
        ];
    }

    /**
     * Offline-пакет для PWA (п. 11): кампания, помещения, минимальный список активов.
     */
    public function offlinePack(InventoryCampaign $c): array
    {
        $assets = [];
        foreach ($this->assets->findForCampaign($c) as $a) {
            $assets[] = [
                'id' => $a->getId()->toRfc4122(),
                'inv' => $a->getInventoryNumber(),
                'bc' => $a->getBarcode(),
                'name' => $a->getShortName(),
                'loc' => $a->getLocation()?->getId(),
                'st' => $a->getStatus()->value,
            ];
        }
        $rooms = [];
        $seen = [];
        foreach ($c->getLocations() as $scope) {
            foreach ($this->em->getRepository(Location::class)->findBy(['active' => true]) as $l) {
                if (!isset($seen[$l->getId()]) && $l->isDescendantOf($scope) && in_array($l->getType(), [LocationType::Room, LocationType::Floor], true)) {
                    $seen[$l->getId()] = true;
                    $rooms[] = ['id' => $l->getId(), 'name' => $l->getPathString(), 'type' => $l->getType()->value];
                }
            }
        }
        if ($c->getLocations()->isEmpty()) {
            foreach ($this->em->getRepository(Location::class)->findBy(['type' => LocationType::Room, 'active' => true]) as $l) {
                $rooms[] = ['id' => $l->getId(), 'name' => $l->getPathString(), 'type' => 'room'];
            }
        }
        usort($rooms, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'campaign' => ['id' => $c->getId(), 'number' => $c->getNumber(), 'name' => $c->getName(), 'status' => $c->getStatus()->value],
            'rooms' => $rooms,
            'assets' => $assets,
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }
}
