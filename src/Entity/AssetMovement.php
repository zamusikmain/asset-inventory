<?php

namespace App\Entity;

use App\Enum\AssetStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Полная история изменения пользователя, статуса и местоположения актива (asset_movements).
 * Хранит снимки названий, чтобы история читалась даже после удаления справочников.
 */
#[ORM\Entity]
#[ORM\Table(name: 'asset_movements')]
#[ORM\Index(columns: ['asset_id', 'performed_at'])]
class AssetMovement
{
    public const TYPE_CREATED = 'created';
    public const TYPE_STATUS = 'status';
    public const TYPE_ASSIGN = 'assign';
    public const TYPE_RETURN = 'return';
    public const TYPE_MOVE = 'move';
    public const TYPE_IMPORT = 'import';
    public const TYPE_INVENTORY = 'inventory';

    public const TYPE_LABELS = [
        self::TYPE_CREATED => 'Создание',
        self::TYPE_STATUS => 'Смена статуса',
        self::TYPE_ASSIGN => 'Выдача',
        self::TYPE_RETURN => 'Возврат',
        self::TYPE_MOVE => 'Перемещение',
        self::TYPE_IMPORT => 'Импорт',
        self::TYPE_INVENTORY => 'Инвентаризация',
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Asset $asset;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(length: 20, nullable: true, enumType: AssetStatus::class)]
    private ?AssetStatus $fromStatus = null;

    #[ORM\Column(length: 20, nullable: true, enumType: AssetStatus::class)]
    private ?AssetStatus $toStatus = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Employee $fromEmployee = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Employee $toEmployee = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromEmployeeName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $toEmployeeName = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Location $fromLocation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Location $toLocation = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $fromLocationName = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $toLocationName = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $performedBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $performedByName = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $performedAt;

    #[ORM\Column(length: 20)]
    private string $source = 'web';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?HandoverDocument $document = null;

    public function __construct(Asset $asset, string $type, ?User $by, string $source = 'web')
    {
        $this->asset = $asset;
        $this->type = $type;
        $this->performedBy = $by;
        $this->performedByName = $by?->__toString();
        $this->source = $source;
        $this->performedAt = new \DateTimeImmutable();
    }

    public function status(?AssetStatus $from, ?AssetStatus $to): static
    {
        $this->fromStatus = $from;
        $this->toStatus = $to;

        return $this;
    }

    public function employee(?Employee $from, ?Employee $to): static
    {
        $this->fromEmployee = $from;
        $this->toEmployee = $to;
        $this->fromEmployeeName = $from?->getName();
        $this->toEmployeeName = $to?->getName();

        return $this;
    }

    public function location(?Location $from, ?Location $to): static
    {
        $this->fromLocation = $from;
        $this->toLocation = $to;
        $this->fromLocationName = $from?->getPathString();
        $this->toLocationName = $to?->getPathString();

        return $this;
    }

    public function withComment(?string $c): static { $this->comment = $c; return $this; }
    public function withDocument(?HandoverDocument $d): static { $this->document = $d; return $this; }

    public function getId(): ?int { return $this->id; }
    public function getAsset(): Asset { return $this->asset; }
    public function getType(): string { return $this->type; }
    public function getTypeLabel(): string { return self::TYPE_LABELS[$this->type] ?? $this->type; }
    public function getFromStatus(): ?AssetStatus { return $this->fromStatus; }
    public function getToStatus(): ?AssetStatus { return $this->toStatus; }
    public function getFromEmployee(): ?Employee { return $this->fromEmployee; }
    public function getToEmployee(): ?Employee { return $this->toEmployee; }
    public function getFromEmployeeName(): ?string { return $this->fromEmployeeName; }
    public function getToEmployeeName(): ?string { return $this->toEmployeeName; }
    public function getFromLocationName(): ?string { return $this->fromLocationName; }
    public function getToLocationName(): ?string { return $this->toLocationName; }
    public function getPerformedByName(): ?string { return $this->performedByName; }
    public function getPerformedAt(): \DateTimeImmutable { return $this->performedAt; }
    public function getSource(): string { return $this->source; }
    public function getComment(): ?string { return $this->comment; }
    public function getDocument(): ?HandoverDocument { return $this->document; }

    public function hasStatusChange(): bool { return $this->fromStatus !== $this->toStatus && $this->toStatus !== null; }
    public function hasEmployeeChange(): bool { return $this->fromEmployeeName !== $this->toEmployeeName; }
    public function hasLocationChange(): bool { return $this->fromLocationName !== $this->toLocationName; }
}
