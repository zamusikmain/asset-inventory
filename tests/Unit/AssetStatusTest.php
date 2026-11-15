<?php

namespace App\Tests\Unit;

use App\Enum\AssetStatus;
use App\Enum\CampaignStatus;
use PHPUnit\Framework\TestCase;

/** Контроль переходов статусов (п. 7 ТЗ). */
class AssetStatusTest extends TestCase
{
    public function testWrittenOffCannotBeIssuedWithoutAdminRestore(): void
    {
        $s = AssetStatus::WrittenOff;
        $this->assertFalse($s->canTransitionTo(AssetStatus::Assigned));
        $this->assertFalse($s->canTransitionTo(AssetStatus::InStock));
        $this->assertTrue($s->canTransitionTo(AssetStatus::InStock, isAdmin: true));
        $this->assertFalse($s->isIssuable());
    }

    public function testRepairCannotBeAssigned(): void
    {
        $this->assertFalse(AssetStatus::Repair->canTransitionTo(AssetStatus::Assigned));
        $this->assertFalse(AssetStatus::Repair->isIssuable());
    }

    public function testAllTransitionTargetsExistAndNoSelfLoops(): void
    {
        foreach (AssetStatus::cases() as $s) {
            $this->assertNotEmpty($s->label());
            $this->assertFalse($s->canTransitionTo($s, true));
        }
        $this->assertTrue(AssetStatus::InStock->canTransitionTo(AssetStatus::Assigned));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('humanStatuses')]
    public function testExcelStatusNormalization(string $raw, ?AssetStatus $expected): void
    {
        $this->assertSame($expected, AssetStatus::fromHuman($raw));
    }

    public static function humanStatuses(): array
    {
        return [['Выдан', AssetStatus::Assigned], ['в работе', AssetStatus::Assigned], ['На складе', AssetStatus::InStock],
            ['Списан', AssetStatus::WrittenOff], ['В ремонте', AssetStatus::Repair], ['странный статус', null], ['', null]];
    }

    public function testCampaignFlow(): void
    {
        $this->assertTrue(CampaignStatus::Draft->canTransitionTo(CampaignStatus::Preparation));
        $this->assertFalse(CampaignStatus::Draft->canTransitionTo(CampaignStatus::Approved));
        $this->assertTrue(CampaignStatus::InProgress->acceptsScans());
        $this->assertFalse(CampaignStatus::Closed->acceptsScans());
    }
}
