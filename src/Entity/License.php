<?php

namespace App\Entity;

use App\Entity\Traits\SyncFieldsTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Программная лицензия (п. 16 ТЗ). Секретный ключ хранится в SecretValue.
 */
#[ORM\Entity]
#[ORM\Table(name: 'licenses')]
class License
{
    use SyncFieldsTrait;

    public const TYPES = [
        'Подписка' => 'subscription',
        'Бессрочная' => 'perpetual',
        'OEM' => 'oem',
        'Корпоративная (volume)' => 'volume',
        'Пробная' => 'trial',
        'Иная' => 'other',
    ];
    public const STATUSES = ['Активна' => 'active', 'Истекает' => 'expiring', 'Истекла' => 'expired', 'Отозвана' => 'revoked'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    private string $vendor = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $product = '';

    #[ORM\Column(length: 20)]
    private string $licenseType = 'subscription';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contract = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subscriptionId = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $seatsTotal = 1;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    private ?string $cost = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = 'KZT';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $supplier = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Employee $responsible = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Department $department = null;

    #[ORM\Column(length: 20)]
    private string $status = 'active';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    /** Признак наличия зашифрованного ключа (значение — в secret_values) */
    #[ORM\Column]
    private bool $hasSecretKey = false;

    /** @var Collection<int, LicenseAssignment> */
    #[ORM\OneToMany(mappedBy: 'license', targetEntity: LicenseAssignment::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $assignments;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->assignments = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getVendor(): string { return $this->vendor; }
    public function setVendor(string $v): static { $this->vendor = $v; return $this; }
    public function getProduct(): string { return $this->product; }
    public function setProduct(string $v): static { $this->product = $v; return $this; }
    public function getLicenseType(): string { return $this->licenseType; }
    public function setLicenseType(string $v): static { $this->licenseType = $v; return $this; }
    public function getLicenseTypeLabel(): string { return array_search($this->licenseType, self::TYPES, true) ?: $this->licenseType; }
    public function getContract(): ?string { return $this->contract; }
    public function setContract(?string $v): static { $this->contract = $v; return $this; }
    public function getSubscriptionId(): ?string { return $this->subscriptionId; }
    public function setSubscriptionId(?string $v): static { $this->subscriptionId = $v; return $this; }
    public function getSeatsTotal(): int { return $this->seatsTotal; }
    public function setSeatsTotal(int $v): static { $this->seatsTotal = $v; return $this; }
    public function getValidFrom(): ?\DateTimeImmutable { return $this->validFrom; }
    public function setValidFrom(?\DateTimeImmutable $v): static { $this->validFrom = $v; return $this; }
    public function getValidUntil(): ?\DateTimeImmutable { return $this->validUntil; }
    public function setValidUntil(?\DateTimeImmutable $v): static { $this->validUntil = $v; return $this; }
    public function getCost(): ?string { return $this->cost; }
    public function setCost(?string $v): static { $this->cost = $v; return $this; }
    public function getCurrency(): ?string { return $this->currency; }
    public function setCurrency(?string $v): static { $this->currency = $v; return $this; }
    public function getSupplier(): ?string { return $this->supplier; }
    public function setSupplier(?string $v): static { $this->supplier = $v; return $this; }
    public function getResponsible(): ?Employee { return $this->responsible; }
    public function setResponsible(?Employee $v): static { $this->responsible = $v; return $this; }
    public function getDepartment(): ?Department { return $this->department; }
    public function setDepartment(?Department $v): static { $this->department = $v; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $v): static { $this->comment = $v; return $this; }
    public function hasSecretKey(): bool { return $this->hasSecretKey; }
    public function setHasSecretKey(bool $v): static { $this->hasSecretKey = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, LicenseAssignment> */
    public function getAssignments(): Collection { return $this->assignments; }

    /** @return list<LicenseAssignment> */
    public function getActiveAssignments(): array
    {
        return array_values($this->assignments->filter(fn (LicenseAssignment $a) => $a->isActive())->toArray());
    }

    public function getSeatsUsed(): int { return count($this->getActiveAssignments()); }
    public function getSeatsFree(): int { return max(0, $this->seatsTotal - $this->getSeatsUsed()); }

    public function getEffectiveStatus(): string
    {
        if ($this->status === 'revoked') {
            return 'revoked';
        }
        if ($this->validUntil) {
            $today = new \DateTimeImmutable('today');
            if ($this->validUntil < $today) {
                return 'expired';
            }
            if ($this->validUntil < $today->modify('+30 days')) {
                return 'expiring';
            }
        }

        return 'active';
    }

    public function getEffectiveStatusLabel(): string
    {
        return array_search($this->getEffectiveStatus(), self::STATUSES, true) ?: $this->status;
    }

    public function __toString(): string { return $this->vendor . ' ' . $this->product; }
}
