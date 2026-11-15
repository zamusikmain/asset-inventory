<?php

namespace App\Entity;

use App\Enum\ScanResult;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Скан в рамках кампании. id = UUID операции, сгенерированный клиентом (PWA),
 * поэтому повторная отправка той же операции не создает дубль (п. 11 ТЗ).
 */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_scans')]
#[ORM\Index(columns: ['campaign_id', 'asset_id'])]
#[ORM\Index(columns: ['campaign_id', 'raw_code'])]
class InventoryScan
{
    public const SYNC_SYNCED = 'synced';
    public const SYNC_CONFLICT = 'conflict';
    public const SYNC_ERROR = 'error';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private InventoryCampaign $campaign;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Asset $asset = null;

    #[ORM\Column(length: 128)]
    private string $rawCode;

    /** Помещение, в котором проводилось сканирование (фактическое) */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Location $location = null;

    /** Ожидаемое местоположение на момент скана */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Location $expectedLocation = null;

    #[ORM\Column(length: 30, enumType: ScanResult::class)]
    private ScanResult $result;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $scannedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $scannedBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $scannedByName = null;

    /** usb | camera | manual */
    #[ORM\Column(length: 10)]
    private string $source = 'usb';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $deviceId = null;

    #[ORM\Column(length: 10)]
    private string $syncStatus = self::SYNC_SYNCED;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    /** Отметка о повреждении/отсутствии этикетки, поставленная проверяющим */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $flag = null;

    /** Ссылка на первый скан при повторе */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?InventoryScan $duplicateOf = null;

    public function __construct(Uuid $id, InventoryCampaign $campaign, string $rawCode, ScanResult $result, \DateTimeImmutable $scannedAt)
    {
        $this->id = $id;
        $this->campaign = $campaign;
        $this->rawCode = $rawCode;
        $this->result = $result;
        $this->scannedAt = $scannedAt;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getCampaign(): InventoryCampaign { return $this->campaign; }
    public function getAsset(): ?Asset { return $this->asset; }
    public function setAsset(?Asset $v): static { $this->asset = $v; return $this; }
    public function getRawCode(): string { return $this->rawCode; }
    public function getLocation(): ?Location { return $this->location; }
    public function setLocation(?Location $v): static { $this->location = $v; return $this; }
    public function getExpectedLocation(): ?Location { return $this->expectedLocation; }
    public function setExpectedLocation(?Location $v): static { $this->expectedLocation = $v; return $this; }
    public function getResult(): ScanResult { return $this->result; }
    public function setResult(ScanResult $v): static { $this->result = $v; return $this; }
    public function getScannedAt(): \DateTimeImmutable { return $this->scannedAt; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    public function getScannedBy(): ?User { return $this->scannedBy; }

    public function setScannedBy(?User $v): static
    {
        $this->scannedBy = $v;
        $this->scannedByName = $v?->__toString();

        return $this;
    }

    public function getScannedByName(): ?string { return $this->scannedByName; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $v): static { $this->source = $v; return $this; }
    public function getDeviceId(): ?string { return $this->deviceId; }
    public function setDeviceId(?string $v): static { $this->deviceId = $v; return $this; }
    public function getSyncStatus(): string { return $this->syncStatus; }
    public function setSyncStatus(string $v): static { $this->syncStatus = $v; return $this; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $v): static { $this->comment = $v; return $this; }
    public function getFlag(): ?string { return $this->flag; }
    public function setFlag(?string $v): static { $this->flag = $v; return $this; }
    public function getDuplicateOf(): ?InventoryScan { return $this->duplicateOf; }
    public function setDuplicateOf(?InventoryScan $v): static { $this->duplicateOf = $v; return $this; }
}
