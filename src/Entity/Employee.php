<?php

namespace App\Entity;

use App\Repository\EmployeeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Карточка сотрудника (п. 5.2 ТЗ).
 */
#[ORM\Entity(repositoryClass: EmployeeRepository::class)]
#[ORM\Table(name: 'employees')]
#[ORM\Index(columns: ['display_name'])]
#[ORM\Index(columns: ['full_name_ru'])]
class Employee
{
    public const STATUSES = ['Работает' => 'active', 'Уволен' => 'dismissed', 'Декрет/отпуск' => 'leave'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $entraObjectId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $upn = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    /** DisplayName / ФИО ENG (сопоставление с учетной записью) */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $displayName = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fullNameRu = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fullNameEn = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $position = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Department $department = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $company = null;

    /** Офис (узел иерархии локаций); город вычисляется по дереву */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Location $office = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Employee $manager = null;

    #[ORM\Column(length: 20)]
    private string $status = 'active';

    #[ORM\Column(length: 20)]
    private string $source = 'manual';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $external1cId = null;

    /** extensionAttribute с ФИО из 1С (номер атрибута уточняется, п. 24) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $extensionAttribute = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getEntraObjectId(): ?string { return $this->entraObjectId; }
    public function setEntraObjectId(?string $v): static { $this->entraObjectId = $v ?: null; return $this; }
    public function getUpn(): ?string { return $this->upn; }
    public function setUpn(?string $v): static { $this->upn = $v; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $v): static { $this->email = $v; return $this; }
    public function getDisplayName(): string { return $this->displayName; }
    public function setDisplayName(string $v): static { $this->displayName = trim($v); return $this; }
    public function getFullNameRu(): ?string { return $this->fullNameRu; }
    public function setFullNameRu(?string $v): static { $this->fullNameRu = $v ? trim($v) : null; return $this; }
    public function getFullNameEn(): ?string { return $this->fullNameEn; }
    public function setFullNameEn(?string $v): static { $this->fullNameEn = $v ? trim($v) : null; return $this; }
    public function getPosition(): ?string { return $this->position; }
    public function setPosition(?string $v): static { $this->position = $v; return $this; }
    public function getDepartment(): ?Department { return $this->department; }
    public function setDepartment(?Department $v): static { $this->department = $v; return $this; }
    public function getCompany(): ?string { return $this->company; }
    public function setCompany(?string $v): static { $this->company = $v; return $this; }
    public function getOffice(): ?Location { return $this->office; }
    public function setOffice(?Location $v): static { $this->office = $v; return $this; }
    public function getCity(): ?Location { return $this->office?->getAncestorOfType(\App\Enum\LocationType::City); }
    public function getManager(): ?Employee { return $this->manager; }
    public function setManager(?Employee $v): static { $this->manager = $v; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }
    public function getStatusLabel(): string { return array_search($this->status, self::STATUSES, true) ?: $this->status; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $v): static { $this->source = $v; return $this; }
    public function getLastSyncAt(): ?\DateTimeImmutable { return $this->lastSyncAt; }
    public function setLastSyncAt(?\DateTimeImmutable $v): static { $this->lastSyncAt = $v; return $this; }
    public function getExternal1cId(): ?string { return $this->external1cId; }
    public function setExternal1cId(?string $v): static { $this->external1cId = $v; return $this; }
    public function getExtensionAttribute(): ?string { return $this->extensionAttribute; }
    public function setExtensionAttribute(?string $v): static { $this->extensionAttribute = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getName(): string
    {
        return $this->fullNameRu ?: $this->displayName;
    }

    public function __toString(): string
    {
        return $this->getName() . ($this->position ? ' — ' . $this->position : '');
    }
}
