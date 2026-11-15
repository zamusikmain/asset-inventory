<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Отдельная таблица зашифрованных секретов (п. 9, 16 ТЗ): recovery keys и
 * лицензионные ключи. Ключ шифрования хранится вне БД (файл/переменная окружения).
 */
#[ORM\Entity]
#[ORM\Table(name: 'secret_values')]
#[ORM\UniqueConstraint(columns: ['owner_type', 'owner_id'])]
class SecretValue
{
    public const OWNER_RECOVERY_KEY = 'asset_recovery_key';
    public const OWNER_LICENSE_KEY = 'license_key';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private string $ownerType;

    #[ORM\Column(length: 64)]
    private string $ownerId;

    #[ORM\Column(type: Types::TEXT)]
    private string $ciphertext;

    #[ORM\Column(length: 16)]
    private string $keyVersion = 'v1';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $updatedBy = null;

    public function __construct(string $ownerType, string $ownerId, string $ciphertext)
    {
        $this->ownerType = $ownerType;
        $this->ownerId = $ownerId;
        $this->ciphertext = $ciphertext;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getOwnerType(): string { return $this->ownerType; }
    public function getOwnerId(): string { return $this->ownerId; }
    public function getCiphertext(): string { return $this->ciphertext; }

    public function update(string $ciphertext, ?string $by): void
    {
        $this->ciphertext = $ciphertext;
        $this->updatedBy = $by;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getUpdatedBy(): ?string { return $this->updatedBy; }
}
