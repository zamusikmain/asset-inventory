<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Акт приема-передачи / возврата (п. 13 ТЗ) и итоговый акт инвентаризации.
 * items — неизменяемый снимок позиций на момент формирования.
 */
#[ORM\Entity]
#[ORM\Table(name: 'handover_documents')]
class HandoverDocument
{
    public const TYPE_ISSUE = 'issue';
    public const TYPE_RETURN = 'return';
    public const TYPE_INVENTORY = 'inventory';

    public const TYPE_LABELS = [
        self::TYPE_ISSUE => 'Акт приема-передачи (выдача)',
        self::TYPE_RETURN => 'Акт возврата',
        self::TYPE_INVENTORY => 'Итоговый акт инвентаризации',
    ];

    public const STATUS_AWAITING = 'awaiting_signature';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_NOT_REQUIRED = 'not_required';

    public const STATUS_LABELS = [
        self::STATUS_AWAITING => 'Ожидает подписи',
        self::STATUS_SIGNED => 'Подписан ЭЦП',
        self::STATUS_REJECTED => 'Подпись отклонена',
        self::STATUS_EXPIRED => 'Срок подписания истек',
        self::STATUS_NOT_REQUIRED => 'Подпись не требуется',
    ];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 30, unique: true)]
    private string $number;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Employee $employee = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?InventoryCampaign $campaign = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $itUser = null;

    /** Снимок данных сотрудника и IT на момент формирования */
    #[ORM\Column(type: Types::JSON)]
    private array $header = [];

    /** Позиции акта (снимок) */
    #[ORM\Column(type: Types::JSON)]
    private array $items = [];

    /** Полный текущий перечень после операции (для уведомления) */
    #[ORM\Column(type: Types::JSON)]
    private array $currentList = [];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pdfPath = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $pdfSha256 = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_AWAITING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $signedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $signDeadline = null;

    /** @var Collection<int, DigitalSignature> */
    #[ORM\OneToMany(mappedBy: 'document', targetEntity: DigitalSignature::class)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $signatures;

    public function __construct(string $number, string $type)
    {
        $this->id = Uuid::v7();
        $this->number = $number;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
        $this->signatures = new ArrayCollection();
    }

    public function getId(): Uuid { return $this->id; }
    public function getNumber(): string { return $this->number; }
    public function getType(): string { return $this->type; }
    public function getTypeLabel(): string { return self::TYPE_LABELS[$this->type] ?? $this->type; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getEmployee(): ?Employee { return $this->employee; }
    public function setEmployee(?Employee $v): static { $this->employee = $v; return $this; }
    public function getCampaign(): ?InventoryCampaign { return $this->campaign; }
    public function setCampaign(?InventoryCampaign $v): static { $this->campaign = $v; return $this; }
    public function getItUser(): ?User { return $this->itUser; }
    public function setItUser(?User $v): static { $this->itUser = $v; return $this; }
    public function getHeader(): array { return $this->header; }
    public function setHeader(array $v): static { $this->header = $v; return $this; }
    public function getItems(): array { return $this->items; }
    public function setItems(array $v): static { $this->items = $v; return $this; }
    public function getCurrentList(): array { return $this->currentList; }
    public function setCurrentList(array $v): static { $this->currentList = $v; return $this; }
    public function getPdfPath(): ?string { return $this->pdfPath; }
    public function getPdfSha256(): ?string { return $this->pdfSha256; }

    public function attachPdf(string $path, string $sha256): void
    {
        $this->pdfPath = $path;
        $this->pdfSha256 = $sha256;
    }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }
    public function getStatusLabel(): string { return self::STATUS_LABELS[$this->status] ?? $this->status; }
    public function getSignedAt(): ?\DateTimeImmutable { return $this->signedAt; }
    public function setSignedAt(?\DateTimeImmutable $v): static { $this->signedAt = $v; return $this; }
    public function getSignDeadline(): ?\DateTimeImmutable { return $this->signDeadline; }
    public function setSignDeadline(?\DateTimeImmutable $v): static { $this->signDeadline = $v; return $this; }
    /** @return Collection<int, DigitalSignature> */
    public function getSignatures(): Collection { return $this->signatures; }

    public function getValidSignature(): ?DigitalSignature
    {
        foreach ($this->signatures as $s) {
            if ($s->isValid()) {
                return $s;
            }
        }

        return null;
    }
}
