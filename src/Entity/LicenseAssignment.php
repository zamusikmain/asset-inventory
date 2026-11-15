<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'license_assignments')]
class LicenseAssignment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'assignments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private License $license;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Employee $employee = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Asset $asset = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $assignedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(License $license, ?Employee $employee, ?Asset $asset)
    {
        if (!$employee && !$asset) {
            throw new \InvalidArgumentException('Укажите сотрудника или оборудование');
        }
        $this->license = $license;
        $this->employee = $employee;
        $this->asset = $asset;
        $this->assignedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getLicense(): License { return $this->license; }
    public function getEmployee(): ?Employee { return $this->employee; }
    public function getAsset(): ?Asset { return $this->asset; }
    public function getAssignedAt(): \DateTimeImmutable { return $this->assignedAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function revoke(): void { $this->revokedAt = new \DateTimeImmutable(); }
    public function isActive(): bool { return $this->revokedAt === null; }
}
