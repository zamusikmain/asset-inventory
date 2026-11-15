<?php

namespace App\Tests\Integration;

use App\Entity\AssetAssignment;
use App\Entity\AssetMovement;
use App\Entity\AuditLog;
use App\Entity\HandoverDocument;
use App\Entity\Notification;
use App\Enum\AssetStatus;
use App\Exception\BusinessException;
use App\Repository\AssetRepository;
use App\Service\AssetService;
use App\Service\FileStorage;
use App\Service\HandoverService;

/**
 * Критерии приемки 17–19, 24, 25: поиск/создание по инв. номеру, закрепление и
 * перемещение с историей, PDF-акт и уведомление, аудит.
 */
class HandoverAndHistoryTest extends IntegrationTestCase
{
    public function testFindByInventoryNumberAndBarcode(): void
    {
        $a = $this->asset();
        $repo = $this->service(AssetRepository::class);
        $this->assertSame($a->getId()->toRfc4122(), $repo->findByCode(strtolower($a->getInventoryNumber()) . "\r\n")?->getId()->toRfc4122());
        $this->assertNull($repo->findByCode('NO-SUCH-' . $this->uid()));
    }

    public function testIssueCreatesAssignmentHistoryPdfAndNotification(): void
    {
        $loc = $this->locations();
        $emp = $this->employee();
        $a1 = $this->asset($loc['room1']);
        $a2 = $this->asset($loc['room1']);
        $h = $this->service(HandoverService::class);

        $doc = $h->issue($emp, [$a1, $a2], $this->user, 'тест');
        $h->notify($doc);

        $this->assertSame(AssetStatus::Assigned, $a1->getStatus());
        $this->assertSame($emp, $a1->getEmployee());
        $this->assertCount(2, $doc->getItems());
        $this->assertCount(2, $doc->getCurrentList());
        $this->assertStringStartsWith('АПП-', $doc->getNumber());
        $this->assertSame(HandoverDocument::STATUS_AWAITING, $doc->getStatus());

        // PDF сформирован и не изменен
        $pdf = $h->pdfContent($doc);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame($doc->getPdfSha256(), hash('sha256', $pdf));

        // история и закрепление
        $assign = $this->em->getRepository(AssetAssignment::class)->findOneBy(['asset' => $a1, 'returnedAt' => null]);
        $this->assertNotNull($assign);
        $this->assertSame($doc, $assign->getIssueDocument());
        $mv = $this->em->getRepository(AssetMovement::class)->findBy(['asset' => $a1], ['id' => 'ASC']);
        $this->assertSame([AssetMovement::TYPE_CREATED, AssetMovement::TYPE_ASSIGN], array_map(fn ($m) => $m->getType(), $mv));

        // уведомление с полным перечнем
        $n = $this->em->getRepository(Notification::class)->findOneBy(['document' => $doc]);
        $this->assertNotNull($n);
        $this->assertStringContainsString($a1->getInventoryNumber(), $n->getBodyHtml());
        $this->assertStringContainsString($a2->getInventoryNumber(), $n->getBodyHtml());

        // аудит
        $this->assertNotNull($this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'asset.assign', 'objectId' => $a1->getId()->toRfc4122()]));
        $this->assertNotNull($this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'handover.issue', 'objectId' => $doc->getId()->toRfc4122()]));

        // возврат
        $ret = $h->return($emp, [$a1], $this->user, $loc['room2']);
        $this->assertNull($a1->getEmployee());
        $this->assertSame(AssetStatus::InStock, $a1->getStatus());
        $this->assertSame($loc['room2'], $a1->getLocation());
        $this->assertCount(1, $ret->getCurrentList(), 'В перечне остается только второй актив');
        $this->em->refresh($assign);
        $this->assertNotNull($assign->getReturnedAt());
    }

    public function testWrittenOffOrRepairCannotBeIssued(): void
    {
        $emp = $this->employee();
        $wo = $this->asset(null, AssetStatus::WrittenOff);
        $rep = $this->asset(null, AssetStatus::Repair);
        $h = $this->service(HandoverService::class);
        foreach ([$wo, $rep] as $a) {
            try {
                $h->issue($emp, [$a], $this->user);
                $this->fail('Выдача должна быть запрещена');
            } catch (BusinessException $e) {
                $this->assertNull($a->getEmployee());
            }
        }
    }

    public function testAssetAssignedToOtherCannotBeIssued(): void
    {
        $a = $this->asset();
        $h = $this->service(HandoverService::class);
        $h->issue($this->employee(), [$a], $this->user);
        $this->expectException(BusinessException::class);
        $h->issue($this->employee(), [$a], $this->user);
    }

    public function testMoveKeepsHistory(): void
    {
        $loc = $this->locations();
        $a = $this->asset($loc['room1']);
        $svc = $this->service(AssetService::class);
        $svc->move($a, $loc['room2'], 'переезд');
        $this->em->flush();
        $mv = $this->em->getRepository(AssetMovement::class)->findOneBy(['asset' => $a, 'type' => AssetMovement::TYPE_MOVE]);
        $this->assertSame($loc['room1']->getPathString(), $mv->getFromLocationName());
        $this->assertSame($loc['room2']->getPathString(), $mv->getToLocationName());
    }

    public function testInvalidStatusTransitionRejected(): void
    {
        $a = $this->asset(null, AssetStatus::WrittenOff);
        // тест работает от имени администратора: восстановление разрешено, произвольный переход — нет
        $this->expectException(BusinessException::class);
        $this->service(AssetService::class)->changeStatus($a, AssetStatus::Repair);
    }

    public function testHistoryIsAppendOnly(): void
    {
        $a = $this->asset();
        $this->em->flush();
        $conn = $this->em->getConnection();
        $this->expectException(\Doctrine\DBAL\Exception::class);
        $conn->executeStatement('UPDATE audit_log SET action = :a WHERE object_id = :id', ['a' => 'tampered', 'id' => $a->getId()->toRfc4122()]);
    }

    public function testDocumentStorageOutsidePublic(): void
    {
        $abs = $this->service(FileStorage::class)->absolute('documents/x.pdf');
        $this->assertStringNotContainsString('/public/', $abs);
    }
}
