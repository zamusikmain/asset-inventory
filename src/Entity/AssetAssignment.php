<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Период закрепления актива за сотрудником (asset_assignments).
 */
#[ORM\Entity]
#[ORM\Table(name: 'asset_assignments')]
#[ORM\Index(columns: ['returned_at'])]
class AssetAssignment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Asset $asset;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Employee $employee;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $assignedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $assignedBy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?HandoverDocument $issueDocument = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $returnedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $returnedBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?HandoverDocument $returnDocument = null;

    /** Подтверждено подписью ЭЦП */
    #[ORM\Column]
    private bool $confirmed = false;

    public function __construct(Asset $asset, Employee $employee, ?User $by)
    {
        $this->asset = $asset;
        $this->employee = $employee;
        $this->assignedBy = $by;
        $this->assignedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getAsset(): Asset { return $this->asset; }
    public function getEmployee(): Employee { return $this->employee; }
    public function getAssignedAt(): \DateTimeImmutable { return $this->assignedAt; }
    public function getAssignedBy(): ?User { return $this->assignedBy; }
    public function getIssueDocument(): ?HandoverDocument { return $this->issueDocument; }
    public function setIssueDocument(?HandoverDocument $v): static { $this->issueDocument = $v; return $this; }
    public function getReturnedAt(): ?\DateTimeImmutable { return $this->returnedAt; }
    public function getReturnedBy(): ?User { return $this->returnedBy; }
    public function getReturnDocument(): ?HandoverDocument { return $this->returnDocument; }
    public function setReturnDocument(?HandoverDocument $v): static { $this->returnDocument = $v; return $this; }
    public function isConfirmed(): bool { return $this->confirmed; }
    public function setConfirmed(bool $v): static { $this->confirmed = $v; return $this; }

    public function close(?User $by): void
    {
        $this->returnedAt = new \DateTimeImmutable();
        $this->returnedBy = $by;
    }

    public function isActive(): bool { return $this->returnedAt === null; }
}
