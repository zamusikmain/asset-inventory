<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Строка предварительного отчета импорта.
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_rows')]
#[ORM\Index(columns: ['job_id', 'action'])]
class ImportRow
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_SKIP = 'skip';

    public const ACTION_LABELS = [
        self::ACTION_CREATE => 'Создать',
        self::ACTION_UPDATE => 'Обновить',
        self::ACTION_SKIP => 'Пропустить',
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'rows')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ImportJob $job;

    #[ORM\Column]
    private int $rowNumber;

    /** Исходные значения строки по системным полям */
    #[ORM\Column(type: Types::JSON)]
    private array $raw = [];

    /** Нормализованные значения (id сотрудника/локации, статус и т.д.) */
    #[ORM\Column(type: Types::JSON)]
    private array $normalized = [];

    /** Проблемы: [{level: error|warning, field, message}] */
    #[ORM\Column(type: Types::JSON)]
    private array $issues = [];

    /** Для обновления: [field => [old, new]] */
    #[ORM\Column(type: Types::JSON)]
    private array $diff = [];

    #[ORM\Column(length: 10)]
    private string $action = self::ACTION_SKIP;

    /** Предложенное системой действие (для отчета) */
    #[ORM\Column(length: 10)]
    private string $suggestedAction = self::ACTION_SKIP;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $matchedAssetId = null;

    /** Результат записи: created/updated/skipped/error */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $outcome = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $outcomeMessage = null;

    public function __construct(ImportJob $job, int $rowNumber)
    {
        $this->job = $job;
        $this->rowNumber = $rowNumber;
    }

    public function getId(): ?int { return $this->id; }
    public function getJob(): ImportJob { return $this->job; }
    public function getRowNumber(): int { return $this->rowNumber; }
    public function getRaw(): array { return $this->raw; }
    public function setRaw(array $v): static { $this->raw = $v; return $this; }
    public function getNormalized(): array { return $this->normalized; }
    public function setNormalized(array $v): static { $this->normalized = $v; return $this; }
    public function getIssues(): array { return $this->issues; }
    public function setIssues(array $v): static { $this->issues = $v; return $this; }
    public function getDiff(): array { return $this->diff; }
    public function setDiff(array $v): static { $this->diff = $v; return $this; }
    public function getAction(): string { return $this->action; }
    public function setAction(string $v): static { $this->action = $v; return $this; }
    public function getSuggestedAction(): string { return $this->suggestedAction; }
    public function setSuggestedAction(string $v): static { $this->suggestedAction = $v; return $this; }
    public function getMatchedAssetId(): ?string { return $this->matchedAssetId; }
    public function setMatchedAssetId(?string $v): static { $this->matchedAssetId = $v; return $this; }
    public function getOutcome(): ?string { return $this->outcome; }
    public function getOutcomeMessage(): ?string { return $this->outcomeMessage; }

    public function setOutcome(string $outcome, ?string $message = null): static
    {
        $this->outcome = $outcome;
        $this->outcomeMessage = $message;

        return $this;
    }

    public function hasErrors(): bool
    {
        foreach ($this->issues as $i) {
            if (($i['level'] ?? '') === 'error') {
                return true;
            }
        }

        return false;
    }

    public function hasWarnings(): bool
    {
        foreach ($this->issues as $i) {
            if (($i['level'] ?? '') === 'warning') {
                return true;
            }
        }

        return false;
    }
}
