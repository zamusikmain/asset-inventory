<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Тип/категория актива (asset_types). Категория — верхний уровень группировки
 * (Компьютеры, Периферия, Сеть, ПО...), name — конкретный тип (Ноутбук, Монитор).
 */
#[ORM\Entity]
#[ORM\Table(name: 'asset_types')]
#[ORM\UniqueConstraint(columns: ['category', 'name'])]
class AssetType
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private string $category = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private string $name = '';

    /** @var Collection<int, AttributeDefinition> */
    #[ORM\OneToMany(mappedBy: 'assetType', targetEntity: AttributeDefinition::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $attributes;

    public function __construct()
    {
        $this->attributes = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getCategory(): string { return $this->category; }
    public function setCategory(string $v): static { $this->category = trim($v); return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = trim($v); return $this; }
    /** @return Collection<int, AttributeDefinition> */
    public function getAttributes(): Collection { return $this->attributes; }

    public function addAttribute(AttributeDefinition $d): static
    {
        if (!$this->attributes->contains($d)) {
            $this->attributes->add($d);
            $d->setAssetType($this);
        }

        return $this;
    }

    public function removeAttribute(AttributeDefinition $d): static
    {
        $this->attributes->removeElement($d);

        return $this;
    }

    public function __toString(): string { return $this->category . ' / ' . $this->name; }
}
