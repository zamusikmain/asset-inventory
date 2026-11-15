<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Исходящие/входящие события интеграции (outbox) для 1С и Entra ID (п. 17 ТЗ).
 * Система копит события; модуль обмена отключаемый и не блокирует основное приложение.
 */
#[ORM\Entity]
#[ORM\Table(name: 'integration_events')]
#[ORM\Index(columns: ['system', 'status'])]
class IntegrationEvent
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DISABLED = 'disabled';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    /** 1c | entra */
    #[ORM\Column(length: 10)]
    private string $system;

    /** out | in */
    #[ORM\Column(length: 3)]
    private string $direction;

    #[ORM\Column(length: 64)]
    private string $eventType;

    #[ORM\Column(length: 64)]
    private string $entityType;

    #[ORM\Column(length: 64)]
    private string $entityId;

    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column(length: 10)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    public function __construct(string $system, string $direction, string $eventType, string $entityType, string $entityId, array $payload)
    {
        $this->system = $system;
        $this->direction = $direction;
        $this->eventType = $eventType;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string { return $this->id; }
    public function getSystem(): string { return $this->system; }
    public function getDirection(): string { return $this->direction; }
    public function getEventType(): string { return $this->eventType; }
    public function getEntityType(): string { return $this->entityType; }
    public function getEntityId(): string { return $this->entityId; }
    public function getPayload(): array { return $this->payload; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }
    public function getAttempts(): int { return $this->attempts; }
    public function getError(): ?string { return $this->error; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getProcessedAt(): ?\DateTimeImmutable { return $this->processedAt; }

    public function markProcessed(bool $ok, ?string $error = null): void
    {
        ++$this->attempts;
        $this->status = $ok ? self::STATUS_SENT : self::STATUS_FAILED;
        $this->error = $error;
        $this->processedAt = new \DateTimeImmutable();
    }
}
