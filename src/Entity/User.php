<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Учетная запись оператора системы (п. 4, 5.1 ТЗ).
 * Поля UPN / Entra Object ID / source заранее подготовлены под SSO.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[UniqueEntity('username')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLES = [
        'Администратор' => 'ROLE_ADMIN',
        'IT-специалист' => 'ROLE_IT',
        'Инициатор инвентаризации' => 'ROLE_INVENTORY_INITIATOR',
        'Член комиссии' => 'ROLE_COMMISSION',
        'Доступ к секретам (recovery/лицензионные ключи)' => 'ROLE_SECRET_VIEWER',
    ];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $username = '';

    #[ORM\Column(length: 255)]
    private string $fullName = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private bool $mustChangePassword = true;

    #[ORM\Column]
    private int $failedLogins = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $passwordChangedAt = null;

    // --- Подготовка к Entra ID ---
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $upn = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $entraObjectId = null;

    #[ORM\Column(length: 20)]
    private string $source = 'local';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncAt = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Employee $employee = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getUsername(): string { return $this->username; }
    public function setUsername(string $v): static { $this->username = mb_strtolower(trim($v)); return $this; }
    public function getUserIdentifier(): string { return $this->username; }
    public function getFullName(): string { return $this->fullName; }
    public function setFullName(string $v): static { $this->fullName = $v; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $v): static { $this->email = $v; return $this; }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    public function getStoredRoles(): array { return $this->roles; }
    public function setRoles(array $roles): static { $this->roles = array_values($roles); return $this; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $v): static { $this->password = $v; return $this; }
    public function eraseCredentials(): void {}
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $v): static { $this->active = $v; return $this; }
    public function isMustChangePassword(): bool { return $this->mustChangePassword; }
    public function setMustChangePassword(bool $v): static { $this->mustChangePassword = $v; return $this; }
    public function getFailedLogins(): int { return $this->failedLogins; }
    public function setFailedLogins(int $v): static { $this->failedLogins = $v; return $this; }
    public function getLockedUntil(): ?\DateTimeImmutable { return $this->lockedUntil; }
    public function setLockedUntil(?\DateTimeImmutable $v): static { $this->lockedUntil = $v; return $this; }
    public function isLocked(): bool { return $this->lockedUntil !== null && $this->lockedUntil > new \DateTimeImmutable(); }
    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function setLastLoginAt(?\DateTimeImmutable $v): static { $this->lastLoginAt = $v; return $this; }
    public function getPasswordChangedAt(): ?\DateTimeImmutable { return $this->passwordChangedAt; }
    public function setPasswordChangedAt(?\DateTimeImmutable $v): static { $this->passwordChangedAt = $v; return $this; }
    public function getUpn(): ?string { return $this->upn; }
    public function setUpn(?string $v): static { $this->upn = $v; return $this; }
    public function getEntraObjectId(): ?string { return $this->entraObjectId; }
    public function setEntraObjectId(?string $v): static { $this->entraObjectId = $v; return $this; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $v): static { $this->source = $v; return $this; }
    public function getLastSyncAt(): ?\DateTimeImmutable { return $this->lastSyncAt; }
    public function getEmployee(): ?Employee { return $this->employee; }
    public function setEmployee(?Employee $v): static { $this->employee = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function __toString(): string { return $this->fullName ?: $this->username; }
}
