<?php

namespace App\Entity;

use App\Enum\CampaignStatus;
use App\Repository\InventoryCampaignRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Кампания инвентаризации (п. 12.1 ТЗ).
 */
#[ORM\Entity(repositoryClass: InventoryCampaignRepository::class)]
#[ORM\Table(name: 'inventory_campaigns')]
class InventoryCampaign
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    private string $number = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name = '';

    /** Основание: приказ, распоряжение */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $basis = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(length: 20, enumType: CampaignStatus::class)]
    private CampaignStatus $status = CampaignStatus::Draft;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $initiator = null;

    /** Офисы/помещения — охват кампании (с потомками) */
    /** @var Collection<int, Location> */
    #[ORM\ManyToMany(targetEntity: Location::class)]
    #[ORM\JoinTable(name: 'inventory_campaign_locations')]
    private Collection $locations;

    /** Категории (пусто = все) */
    /** @var Collection<int, AssetType> */
    #[ORM\ManyToMany(targetEntity: AssetType::class)]
    #[ORM\JoinTable(name: 'inventory_campaign_types')]
    private Collection $assetTypes;

    /** Ответственные IT */
    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'inventory_campaign_it')]
    private Collection $itResponsible;

    /** @var Collection<int, InventoryCommissionMember> */
    #[ORM\OneToMany(mappedBy: 'campaign', targetEntity: InventoryCommissionMember::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $commission;

    /** Снимок ожидаемого состава на момент старта: [assetId => locationId|null] */
    #[ORM\Column(type: Types::JSON)]
    private array $expectedSnapshot = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct()
    {
        $this->locations = new ArrayCollection();
        $this->assetTypes = new ArrayCollection();
        $this->itResponsible = new ArrayCollection();
        $this->commission = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getNumber(): string { return $this->number; }
    public function setNumber(string $v): static { $this->number = $v; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getBasis(): ?string { return $this->basis; }
    public function setBasis(?string $v): static { $this->basis = $v; return $this; }
    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function setStartDate(?\DateTimeImmutable $v): static { $this->startDate = $v; return $this; }
    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function setEndDate(?\DateTimeImmutable $v): static { $this->endDate = $v; return $this; }
    public function getStatus(): CampaignStatus { return $this->status; }
    public function setStatus(CampaignStatus $v): static { $this->status = $v; return $this; }
    public function getInitiator(): ?User { return $this->initiator; }
    public function setInitiator(?User $v): static { $this->initiator = $v; return $this; }
    /** @return Collection<int, Location> */
    public function getLocations(): Collection { return $this->locations; }
    /** @return Collection<int, AssetType> */
    public function getAssetTypes(): Collection { return $this->assetTypes; }
    /** @return Collection<int, User> */
    public function getItResponsible(): Collection { return $this->itResponsible; }
    /** @return Collection<int, InventoryCommissionMember> */
    public function getCommission(): Collection { return $this->commission; }

    public function addCommissionMember(InventoryCommissionMember $m): void
    {
        if (!$this->commission->contains($m)) {
            $this->commission->add($m);
        }
    }

    public function isCommissionMember(User $u): bool
    {
        foreach ($this->commission as $m) {
            if ($m->getUser() === $u) {
                return true;
            }
        }

        return false;
    }

    public function getExpectedSnapshot(): array { return $this->expectedSnapshot; }
    public function setExpectedSnapshot(array $v): static { $this->expectedSnapshot = $v; return $this; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $v): static { $this->comment = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $v): static { $this->startedAt = $v; return $this; }
    public function getApprovedAt(): ?\DateTimeImmutable { return $this->approvedAt; }
    public function setApprovedAt(?\DateTimeImmutable $v): static { $this->approvedAt = $v; return $this; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function setClosedAt(?\DateTimeImmutable $v): static { $this->closedAt = $v; return $this; }

    /** Входит ли локация в охват кампании. */
    public function coversLocation(?Location $loc): bool
    {
        if ($loc === null) {
            return false;
        }
        if ($this->locations->isEmpty()) {
            return true;
        }
        foreach ($this->locations as $scope) {
            if ($loc->isDescendantOf($scope)) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string { return $this->number . ' — ' . $this->name; }
}
