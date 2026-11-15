<?php

namespace App\Entity;

use App\Entity\Traits\SyncFieldsTrait;
use App\Enum\AssetStatus;
use App\Enum\LocationType;
use App\Repository\AssetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Карточка актива (п. 6.1 ТЗ).
 */
#[ORM\Entity(repositoryClass: AssetRepository::class)]
#[ORM\Table(name: 'assets')]
#[ORM\Index(columns: ['status'])]
#[ORM\Index(columns: ['barcode'])]
#[ORM\Index(columns: ['serial_number'])]
#[ORM\Index(columns: ['archived'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity('inventoryNumber', message: 'Актив с таким инвентарным номером уже существует')]
class Asset
{
    use SyncFieldsTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** Основной инвентарный номер — уникальный идентификатор, кодируется в Code 128 */
    #[ORM\Column(length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[\x20-\x7E]+$/', message: 'Инвентарный номер должен содержать только ASCII-символы (Code 128)')]
    #[Assert\Length(max: 64)]
    private string $inventoryNumber = '';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $fullInventoryNumber = null;

    #[ORM\Column(length: 64)]
    private string $barcode = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?AssetType $type = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $manufacturer = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $serialNumber = null;

    #[ORM\Column(length: 20, enumType: AssetStatus::class)]
    private AssetStatus $status = AssetStatus::Created;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Employee $employee = null;

    /** Текущее местоположение — помещение (или иной узел иерархии) */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Location $location = null;

    #[ORM\ManyToOne(inversedBy: 'assets')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?AssetBundle $bundle = null;

    #[ORM\Column(name: 'asset_condition', length: 30, nullable: true)]
    private ?string $condition = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    /** Дата исходной записи (из Excel) */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sourceDate = null;

    // --- BitLocker/FileVault: по умолчанию храним только признак (п. 9 ТЗ) ---
    #[ORM\Column]
    private bool $hasRecoveryKey = false;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $recoveryKeyStorage = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $recoveryKeyDeviceId = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $recoveryKeyCheckedAt = null;

    #[ORM\Column]
    private bool $archived = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, AssetAttributeValue> */
    #[ORM\OneToMany(mappedBy: 'asset', targetEntity: AssetAttributeValue::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $attributeValues;

    public const CONDITIONS = ['Новое' => 'new', 'Хорошее' => 'good', 'Удовлетворительное' => 'fair', 'Неисправно' => 'broken'];

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->attributeValues = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getInventoryNumber(): string { return $this->inventoryNumber; }

    public function setInventoryNumber(string $v): static
    {
        $v = self::normalizeCode($v);
        if ($this->barcode === '' || $this->barcode === $this->inventoryNumber) {
            $this->barcode = $v;
        }
        $this->inventoryNumber = $v;

        return $this;
    }

    public function getFullInventoryNumber(): ?string { return $this->fullInventoryNumber; }
    public function setFullInventoryNumber(?string $v): static { $this->fullInventoryNumber = $v ? trim($v) : null; return $this; }
    public function getBarcode(): string { return $this->barcode; }
    public function setBarcode(?string $v): static { $this->barcode = $v ? self::normalizeCode($v) : $this->inventoryNumber; return $this; }
    public function getType(): ?AssetType { return $this->type; }
    public function setType(?AssetType $v): static { $this->type = $v; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = trim($v); return $this; }
    public function getManufacturer(): ?string { return $this->manufacturer; }
    public function setManufacturer(?string $v): static { $this->manufacturer = $v; return $this; }
    public function getModel(): ?string { return $this->model; }
    public function setModel(?string $v): static { $this->model = $v; return $this; }
    public function getSerialNumber(): ?string { return $this->serialNumber; }
    public function setSerialNumber(?string $v): static { $this->serialNumber = $v ? trim($v) : null; return $this; }
    public function getStatus(): AssetStatus { return $this->status; }

    /** Только для AssetService — он проверяет переходы и пишет историю. */
    public function setStatus(AssetStatus $v): static { $this->status = $v; return $this; }
    public function getEmployee(): ?Employee { return $this->employee; }
    public function setEmployee(?Employee $v): static { $this->employee = $v; return $this; }
    public function getLocation(): ?Location { return $this->location; }
    public function setLocation(?Location $v): static { $this->location = $v; return $this; }
    public function getBundle(): ?AssetBundle { return $this->bundle; }
    public function setBundle(?AssetBundle $v): static { $this->bundle = $v; return $this; }
    public function getCondition(): ?string { return $this->condition; }
    public function setCondition(?string $v): static { $this->condition = $v; return $this; }
    public function getConditionLabel(): ?string { return $this->condition ? (array_search($this->condition, self::CONDITIONS, true) ?: $this->condition) : null; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $v): static { $this->comment = $v; return $this; }
    public function getSourceDate(): ?\DateTimeImmutable { return $this->sourceDate; }
    public function setSourceDate(?\DateTimeImmutable $v): static { $this->sourceDate = $v; return $this; }
    public function hasRecoveryKey(): bool { return $this->hasRecoveryKey; }
    public function isHasRecoveryKey(): bool { return $this->hasRecoveryKey; }
    public function setHasRecoveryKey(bool $v): static { $this->hasRecoveryKey = $v; return $this; }
    public function getRecoveryKeyStorage(): ?string { return $this->recoveryKeyStorage; }
    public function setRecoveryKeyStorage(?string $v): static { $this->recoveryKeyStorage = $v; return $this; }
    public function getRecoveryKeyDeviceId(): ?string { return $this->recoveryKeyDeviceId; }
    public function setRecoveryKeyDeviceId(?string $v): static { $this->recoveryKeyDeviceId = $v; return $this; }
    public function getRecoveryKeyCheckedAt(): ?\DateTimeImmutable { return $this->recoveryKeyCheckedAt; }
    public function setRecoveryKeyCheckedAt(?\DateTimeImmutable $v): static { $this->recoveryKeyCheckedAt = $v; return $this; }
    public function isArchived(): bool { return $this->archived; }
    public function setArchived(bool $v): static { $this->archived = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, AssetAttributeValue> */
    public function getAttributeValues(): Collection { return $this->attributeValues; }

    public function getAttributeValue(AttributeDefinition $def): ?AssetAttributeValue
    {
        foreach ($this->attributeValues as $v) {
            if ($v->getDefinition() === $def) {
                return $v;
            }
        }

        return null;
    }

    public function setAttribute(AttributeDefinition $def, ?string $value): void
    {
        $existing = $this->getAttributeValue($def);
        if ($value === null) {
            if ($existing) {
                $this->attributeValues->removeElement($existing);
            }

            return;
        }
        if ($existing) {
            $existing->setValue($value);
        } else {
            $this->attributeValues->add(new AssetAttributeValue($this, $def, $value));
        }
    }

    // --- Производные поля местоположения (город, офис, этаж, помещение) ---
    public function getCity(): ?Location { return $this->location?->getAncestorOfType(LocationType::City); }
    public function getOffice(): ?Location { return $this->location?->getAncestorOfType(LocationType::Office); }
    public function getFloor(): ?Location { return $this->location?->getAncestorOfType(LocationType::Floor); }
    public function getRoom(): ?Location { return $this->location?->getAncestorOfType(LocationType::Room); }

    public static function normalizeCode(string $code): string
    {
        // Сканер в режиме клавиатуры может добавлять пробелы/переводы строки
        return strtoupper(trim(preg_replace('/[\r\n\t]+/', '', $code)));
    }

    public function getShortName(): string
    {
        return mb_strimwidth(trim(($this->manufacturer ? $this->manufacturer . ' ' : '') . ($this->model ?: $this->name)), 0, 40, '…');
    }

    public function __toString(): string
    {
        return $this->inventoryNumber . ' — ' . $this->name;
    }
}
