<?php

namespace App\Tests\Integration;

use App\Entity\AssetMovement;
use App\Entity\InventoryCampaign;
use App\Entity\InventoryDiscrepancy;
use App\Entity\InventoryScan;
use App\Enum\AssetStatus;
use App\Enum\CampaignStatus;
use App\Enum\ScanResult;
use App\Exception\BusinessException;
use App\Service\InventoryService;
use Symfony\Component\Uid\Uuid;

/**
 * Критерии 21–23: повторное сканирование блокируется, offline-операции идемпотентны,
 * конфликты не теряются; п. 12.3 — смена места только после подтверждения IT.
 */
class InventoryTest extends IntegrationTestCase
{
    private function campaign(array $loc): InventoryCampaign
    {
        $c = (new InventoryCampaign())->setNumber($this->uid('INV'))->setName('Тест')->setInitiator($this->user);
        $c->getLocations()->add($loc['office']);
        $c->getItResponsible()->add($this->user);
        $this->em->persist($c);
        $this->em->flush();
        $inv = $this->service(InventoryService::class);
        $inv->transition($c, CampaignStatus::Preparation, $this->user);
        $inv->transition($c, CampaignStatus::Started, $this->user);
        $this->em->flush();

        return $c;
    }

    public function testScanResultsAndDuplicateBlocking(): void
    {
        $loc = $this->locations();
        $a = $this->asset($loc['room1']);
        $c = $this->campaign($loc);
        $inv = $this->service(InventoryService::class);

        $s1 = $inv->scan($c, $a->getInventoryNumber(), $this->user, null, ['location' => $loc['room1']]);
        $this->assertSame(ScanResult::FoundExpected, $s1->getResult());
        $this->assertSame(CampaignStatus::InProgress, $c->getStatus());

        $s2 = $inv->scan($c, $a->getInventoryNumber(), $this->user, null, ['location' => $loc['room1']]);
        $this->assertSame(ScanResult::Duplicate, $s2->getResult());
        $this->assertSame($s1->getId()->toRfc4122(), $s2->getDuplicateOf()?->getId()->toRfc4122(), 'Указан первый скан (время и пользователь)');

        $this->assertSame(ScanResult::Unknown, $inv->scan($c, 'NOPE-' . $this->uid(), $this->user)->getResult());
        $this->assertSame(ScanResult::InvalidCode, $inv->scan($c, 'Кириллица', $this->user)->getResult());
    }

    public function testOtherRoomRequiresItConfirmationAndKeepsHistory(): void
    {
        $loc = $this->locations();
        $a = $this->asset($loc['room1']);
        $b = $this->asset($loc['room1']);
        $c = $this->campaign($loc);
        $inv = $this->service(InventoryService::class);

        $s = $inv->scan($c, $a->getInventoryNumber(), $this->user, null, ['location' => $loc['room2']]);
        $this->assertSame(ScanResult::FoundOtherRoom, $s->getResult());
        $this->assertSame($loc['room1'], $a->getLocation(), 'До подтверждения IT место не меняется');
        $this->assertSame(ScanResult::FoundOtherOffice, $inv->scan($c, $b->getInventoryNumber(), $this->user, null, ['location' => $loc['otherRoom']])->getResult());

        $d = $this->em->getRepository(InventoryDiscrepancy::class)->findOneBy(['scan' => $s]);
        $this->assertSame($loc['room1'], $d->getExpectedLocation());
        $this->assertSame($loc['room2'], $d->getActualLocation());

        $inv->resolve($d, true, $this->user, 'подтверждаю');
        $this->em->flush();
        $this->assertSame($loc['room2'], $a->getLocation());
        $mv = $this->em->getRepository(AssetMovement::class)->findOneBy(['asset' => $a, 'type' => AssetMovement::TYPE_INVENTORY]);
        $this->assertNotNull($mv);
        $this->assertSame($loc['room1']->getPathString(), $mv->getFromLocationName());
    }

    public function testOfflineSyncIsIdempotentAndConflictsAreKept(): void
    {
        $loc = $this->locations();
        $a = $this->asset($loc['room1']);
        $moved = $this->asset($loc['room1']);
        $c = $this->campaign($loc);
        $inv = $this->service(InventoryService::class);

        $op = Uuid::v4();
        $first = $inv->scan($c, $a->getInventoryNumber(), $this->user, $op, ['source' => 'camera', 'expectedLocationId' => $loc['room1']->getId(), 'location' => $loc['room1']]);
        $again = $inv->scan($c, $a->getInventoryNumber(), $this->user, $op, ['source' => 'camera', 'expectedLocationId' => $loc['room1']->getId(), 'location' => $loc['room1']]);
        $this->assertSame($first->getId()->toRfc4122(), $again->getId()->toRfc4122());
        $this->assertSame(1, $this->em->getRepository(InventoryScan::class)->count(['campaign' => $c, 'asset' => $a]), 'Повторная отправка UUID не создает дубль');

        // актив переместили на сервере после выгрузки пакета → конфликт
        $moved->setLocation($loc['room2']);
        $this->em->flush();
        $conf = $inv->scan($c, $moved->getInventoryNumber(), $this->user, Uuid::v4(), ['source' => 'camera', 'expectedLocationId' => $loc['room1']->getId(), 'location' => $loc['room1']]);
        $this->assertSame(InventoryScan::SYNC_CONFLICT, $conf->getSyncStatus());
        $this->assertNotNull($this->em->getRepository(InventoryDiscrepancy::class)->findOneBy(['scan' => $conf]), 'Конфликт передан на ручную проверку');

        // скан, пришедший после завершения сканирования, сохраняется как конфликт
        $inv->transition($c, CampaignStatus::AwaitingReview, $this->user);
        $this->em->flush();
        $late = $inv->scan($c, $a->getInventoryNumber() . 'X', $this->user, Uuid::v4(), ['source' => 'camera']);
        $this->assertSame(InventoryScan::SYNC_CONFLICT, $late->getSyncStatus());

        // USB-скан в закрытую кампанию запрещен
        $this->expectException(BusinessException::class);
        $inv->scan($c, $a->getInventoryNumber(), $this->user, null, ['source' => 'usb']);
    }

    public function testNotFoundGeneratedAndApprovalRequiresResolution(): void
    {
        $loc = $this->locations();
        $missing = $this->asset($loc['room1']);
        $c = $this->campaign($loc);
        $inv = $this->service(InventoryService::class);
        $inv->transition($c, CampaignStatus::InProgress, $this->user);
        $inv->transition($c, CampaignStatus::AwaitingReview, $this->user);
        $this->em->flush();

        $d = $this->em->getRepository(InventoryDiscrepancy::class)->findOneBy(['campaign' => $c, 'asset' => $missing]);
        $this->assertSame(ScanResult::NotFound, $d->getType());
        $inv->transition($c, CampaignStatus::WithCommission, $this->user);
        try {
            $inv->transition($c, CampaignStatus::Approved, $this->user);
            $this->fail('Утверждение с открытыми расхождениями запрещено');
        } catch (BusinessException) {
        }
        $inv->resolve($d, true, $this->user, null);
        $this->em->flush();
        $this->assertSame(AssetStatus::NotFound, $missing->getStatus());
        $inv->transition($c, CampaignStatus::Approved, $this->user);
        $this->assertSame(CampaignStatus::Approved, $c->getStatus());
    }
}
