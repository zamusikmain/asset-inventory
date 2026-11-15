<?php

namespace App\Entity;

use App\Enum\ScanResult;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Расхождение инвентаризации (п. 12.3 ТЗ). Местоположение актива меняется
 * только после подтверждения IT.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_discrepancies')]
#[ORM\Index(columns: ['campaign_id', 'status'])]
class InventoryDiscrepancy
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Ожидает решения',
        self::STATUS_CONFIRMED => 'Подтверждено',
        self::STATUS_REJECTED => 'Отклонено',
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private InventoryCampaign $campaign;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Asset $asset = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?InventoryScan $scan = null;

    #[ORM\Column(length: 30, enumType: ScanResult::class)]
    private ScanResult $type;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $rawCode = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Location $expectedLocation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Location $actualLocation = null;

    #[ORM\Column(length: 10)]
    private string $status = self::STATUS_OPEN;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $resolvedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(InventoryCampaign $campaign, ScanResult $type)
    {
        $this->campaign = $campaign;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCampaign(): InventoryCampaign { return $this->campaign; }
    public function getAsset(): ?Asset { return $this->asset; }
    public function setAsset(?Asset $v): static { $this->asset = $v; return $this; }
    public function getScan(): ?InventoryScan { return $this->scan; }
    public function setScan(?InventoryScan $v): static { $this->scan = $v; return $this; }
    public function getType(): ScanResult { return $this->type; }
    public function getRawCode(): ?string { return $this->rawCode; }
    public function setRawCode(?string $v): static { $this->rawCode = $v; return $this; }
    public function getExpectedLocation(): ?Location { return $this->expectedLocation; }
    public function setExpectedLocation(?Location $v): static { $this->expectedLocation = $v; return $this; }
    public function getActualLocation(): ?Location { return $this->actualLocation; }
    public function setActualLocation(?Location $v): static { $this->actualLocation = $v; return $this; }
    public function getStatus(): string { return $this->status; }
    public function getStatusLabel(): string { return self::STATUS_LABELS[$this->status]; }
    public function isOpen(): bool { return $this->status === self::STATUS_OPEN; }
    public function getResolvedBy(): ?User { return $this->resolvedBy; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $v): static { $this->comment = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function resolve(bool $confirmed, User $by, ?string $comment): void
    {
        $this->status = $confirmed ? self::STATUS_CONFIRMED : self::STATUS_REJECTED;
        $this->resolvedBy = $by;
        $this->resolvedAt = new \DateTimeImmutable();
        if ($comment) {
            $this->comment = $comment;
        }
    }
}
