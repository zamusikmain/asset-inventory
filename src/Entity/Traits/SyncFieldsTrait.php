<?php

namespace App\Entity\Traits;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Поля для будущей интеграции с 1С / Entra ID (п. 17 ТЗ).
 */
trait SyncFieldsTrait
{
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $external1cId = null;

    #[ORM\Column(length: 30)]
    private string $sourceSystem = 'manual';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $syncStatus = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncAt = null;

    #[ORM\Column]
    private int $syncVersion = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastSyncError = null;

    public function getExternal1cId(): ?string { return $this->external1cId; }
    public function setExternal1cId(?string $v): static { $this->external1cId = $v ?: null; return $this; }
    public function getSourceSystem(): string { return $this->sourceSystem; }
    public function setSourceSystem(string $v): static { $this->sourceSystem = $v; return $this; }
    public function getSyncStatus(): ?string { return $this->syncStatus; }
    public function setSyncStatus(?string $v): static { $this->syncStatus = $v; return $this; }
    public function getLastSyncAt(): ?\DateTimeImmutable { return $this->lastSyncAt; }
    public function setLastSyncAt(?\DateTimeImmutable $v): static { $this->lastSyncAt = $v; return $this; }
    public function getSyncVersion(): int { return $this->syncVersion; }
    public function setSyncVersion(int $v): static { $this->syncVersion = $v; return $this; }
    public function getLastSyncError(): ?string { return $this->lastSyncError; }
    public function setLastSyncError(?string $v): static { $this->lastSyncError = $v; return $this; }
}
