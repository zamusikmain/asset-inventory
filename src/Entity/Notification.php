<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Уведомление сотрудника (п. 14 ТЗ), отправляется асинхронно через внутренний SMTP.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notifications')]
class Notification
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Employee $employee = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $recipient = null;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(type: Types::TEXT)]
    private string $bodyHtml;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?HandoverDocument $document = null;

    #[ORM\Column(length: 10)]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    public function __construct(?Employee $employee, ?string $recipient, string $subject, string $bodyHtml)
    {
        $this->employee = $employee;
        $this->recipient = $recipient;
        $this->subject = $subject;
        $this->bodyHtml = $bodyHtml;
        $this->createdAt = new \DateTimeImmutable();
        if (!$recipient) {
            $this->status = self::STATUS_SKIPPED;
            $this->error = 'У сотрудника не указан email';
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getEmployee(): ?Employee { return $this->employee; }
    public function getRecipient(): ?string { return $this->recipient; }
    public function getSubject(): string { return $this->subject; }
    public function getBodyHtml(): string { return $this->bodyHtml; }
    public function getDocument(): ?HandoverDocument { return $this->document; }
    public function setDocument(?HandoverDocument $v): static { $this->document = $v; return $this; }
    public function getStatus(): string { return $this->status; }
    public function getError(): ?string { return $this->error; }
    public function getAttempts(): int { return $this->attempts; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }

    public function markSent(): void
    {
        $this->status = self::STATUS_SENT;
        $this->sentAt = new \DateTimeImmutable();
        $this->error = null;
        ++$this->attempts;
    }

    public function markFailed(string $error): void
    {
        $this->status = self::STATUS_FAILED;
        $this->error = $error;
        ++$this->attempts;
    }
}
