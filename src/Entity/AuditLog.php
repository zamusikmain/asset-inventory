<?php

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Журнал аудита (п. 18.1 ТЗ). Без внешних ключей на объекты: история не удаляется
 * вместе с основной записью.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(columns: ['occurred_at'])]
#[ORM\Index(columns: ['object_type', 'object_id'])]
#[ORM\Index(columns: ['action'])]
class AuditLog
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $userId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 64)]
    private string $action;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $objectType = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $objectId = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $objectLabel = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $oldValues = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $newValues = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $sessionId = null;

    /** web | api | pwa | cli | import | system */
    #[ORM\Column(length: 20)]
    private string $source = 'web';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $relatedDocument = null;

    public function __construct(string $action)
    {
        $this->action = $action;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?string { return $this->id; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function getUserId(): ?string { return $this->userId; }
    public function getUsername(): ?string { return $this->username; }
    public function getAction(): string { return $this->action; }
    public function getObjectType(): ?string { return $this->objectType; }
    public function getObjectId(): ?string { return $this->objectId; }
    public function getObjectLabel(): ?string { return $this->objectLabel; }
    public function getOldValues(): ?array { return $this->oldValues; }
    public function getNewValues(): ?array { return $this->newValues; }
    public function getIp(): ?string { return $this->ip; }
    public function getSessionId(): ?string { return $this->sessionId; }
    public function getSource(): string { return $this->source; }
    public function getComment(): ?string { return $this->comment; }
    public function getRelatedDocument(): ?string { return $this->relatedDocument; }

    public function fill(array $data): static
    {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k) && !in_array($k, ['id', 'occurredAt', 'action'], true)) {
                $this->$k = $v;
            }
        }

        return $this;
    }
}
