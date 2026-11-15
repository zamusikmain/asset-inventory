<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Комплект из самостоятельных активов (п. 6.3 ТЗ). Каждый компонент сохраняет
 * собственный инвентарный номер, штрихкод, статус и историю.
 */
#[ORM\Entity]
#[ORM\Table(name: 'asset_bundles')]
class AssetBundle
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Asset> */
    #[ORM\OneToMany(mappedBy: 'bundle', targetEntity: Asset::class)]
    #[ORM\OrderBy(['inventoryNumber' => 'ASC'])]
    private Collection $assets;

    public function __construct()
    {
        $this->assets = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $v): static { $this->comment = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** @return Collection<int, Asset> */
    public function getAssets(): Collection { return $this->assets; }
    public function __toString(): string { return $this->name; }
}
