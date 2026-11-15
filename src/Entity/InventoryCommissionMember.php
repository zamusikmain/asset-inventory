<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'inventory_commission_members')]
#[ORM\UniqueConstraint(columns: ['campaign_id', 'user_id'])]
class InventoryCommissionMember
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'commission')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private InventoryCampaign $campaign;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private bool $chairman = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    public function __construct(InventoryCampaign $campaign, User $user, bool $chairman = false)
    {
        $this->campaign = $campaign;
        $this->user = $user;
        $this->chairman = $chairman;
    }

    public function getId(): ?int { return $this->id; }
    public function getCampaign(): InventoryCampaign { return $this->campaign; }
    public function getUser(): User { return $this->user; }
    public function isChairman(): bool { return $this->chairman; }
    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }
    public function getComment(): ?string { return $this->comment; }

    public function confirm(?string $comment): void
    {
        $this->confirmedAt = new \DateTimeImmutable();
        $this->comment = $comment;
    }
}
