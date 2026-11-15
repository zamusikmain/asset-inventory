<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Задание импорта Excel (п. 8 ТЗ). Данные реестра не меняются до явного подтверждения.
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_jobs')]
class ImportJob
{
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_ANALYZED = 'analyzed';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_UPLOADED => 'Загружен, требуется сопоставление',
        self::STATUS_ANALYZED => 'Проанализирован, ожидает подтверждения',
        self::STATUS_COMMITTED => 'Записан',
        self::STATUS_CANCELLED => 'Отменен',
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 255)]
    private string $storagePath;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $sheetName = null;

    #[ORM\Column]
    private int $headerRow = 1;

    /** Заголовки файла: [colIndex => header] */
    #[ORM\Column(type: Types::JSON)]
    private array $headers = [];

    /** Сопоставление: [systemField => colIndex] */
    #[ORM\Column(type: Types::JSON)]
    private array $columnMap = [];

    #[ORM\Column(type: Types::JSON)]
    private array $summary = [];

    /** Итоговый протокол после записи */
    #[ORM\Column(type: Types::JSON)]
    private array $protocol = [];

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_UPLOADED;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $committedAt = null;

    /** @var Collection<int, ImportRow> */
    #[ORM\OneToMany(mappedBy: 'job', targetEntity: ImportRow::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['rowNumber' => 'ASC'])]
    private Collection $rows;

    public function __construct(string $originalName, string $storagePath, string $sha256)
    {
        $this->originalName = $originalName;
        $this->storagePath = $storagePath;
        $this->sha256 = $sha256;
        $this->createdAt = new \DateTimeImmutable();
        $this->rows = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getStoragePath(): string { return $this->storagePath; }
    public function getSha256(): string { return $this->sha256; }
    public function getSheetName(): ?string { return $this->sheetName; }
    public function setSheetName(?string $v): static { $this->sheetName = $v; return $this; }
    public function getHeaderRow(): int { return $this->headerRow; }
    public function setHeaderRow(int $v): static { $this->headerRow = $v; return $this; }
    public function getHeaders(): array { return $this->headers; }
    public function setHeaders(array $v): static { $this->headers = $v; return $this; }
    public function getColumnMap(): array { return $this->columnMap; }
    public function setColumnMap(array $v): static { $this->columnMap = $v; return $this; }
    public function getSummary(): array { return $this->summary; }
    public function setSummary(array $v): static { $this->summary = $v; return $this; }
    public function getProtocol(): array { return $this->protocol; }
    public function setProtocol(array $v): static { $this->protocol = $v; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }
    public function getStatusLabel(): string { return self::STATUS_LABELS[$this->status] ?? $this->status; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $v): static { $this->createdBy = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCommittedAt(): ?\DateTimeImmutable { return $this->committedAt; }
    public function setCommittedAt(?\DateTimeImmutable $v): static { $this->committedAt = $v; return $this; }
    /** @return Collection<int, ImportRow> */
    public function getRows(): Collection { return $this->rows; }

    public function addRow(ImportRow $r): void
    {
        $this->rows->add($r);
    }

    public function clearRows(): void
    {
        $this->rows->clear();
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_UPLOADED, self::STATUS_ANALYZED], true);
    }
}
