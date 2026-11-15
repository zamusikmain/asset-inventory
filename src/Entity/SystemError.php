<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Журнал технических ошибок (п. 2.1, 19 ТЗ).
 */
#[ORM\Entity]
#[ORM\Table(name: 'system_errors')]
#[ORM\Index(columns: ['occurred_at'])]
class SystemError
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 20)]
    private string $level;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $exceptionClass = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $trace = null;

    #[ORM\Column(length: 2000, nullable: true)]
    private ?string $requestUri = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $username = null;

    #[ORM\Column]
    private bool $notified = false;

    public function __construct(string $level, string $message)
    {
        $this->level = $level;
        $this->message = mb_substr($message, 0, 10000);
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?string { return $this->id; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function getLevel(): string { return $this->level; }
    public function getMessage(): string { return $this->message; }
    public function getExceptionClass(): ?string { return $this->exceptionClass; }
    public function setExceptionClass(?string $v): static { $this->exceptionClass = $v; return $this; }
    public function getTrace(): ?string { return $this->trace; }
    public function setTrace(?string $v): static { $this->trace = $v ? mb_substr($v, 0, 20000) : null; return $this; }
    public function getRequestUri(): ?string { return $this->requestUri; }
    public function setRequestUri(?string $v): static { $this->requestUri = $v ? mb_substr($v, 0, 2000) : null; return $this; }
    public function getUsername(): ?string { return $this->username; }
    public function setUsername(?string $v): static { $this->username = $v; return $this; }
    public function isNotified(): bool { return $this->notified; }
    public function setNotified(bool $v): static { $this->notified = $v; return $this; }
}
