<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'asset_attribute_values')]
#[ORM\UniqueConstraint(columns: ['asset_id', 'definition_id'])]
class AssetAttributeValue
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'attributeValues')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Asset $asset;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AttributeDefinition $definition;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $value = null;

    public function __construct(Asset $asset, AttributeDefinition $definition, ?string $value)
    {
        $this->asset = $asset;
        $this->definition = $definition;
        $this->value = $value;
    }

    public function getId(): ?int { return $this->id; }
    public function getAsset(): Asset { return $this->asset; }
    public function getDefinition(): AttributeDefinition { return $this->definition; }
    public function getValue(): ?string { return $this->value; }
    public function setValue(?string $v): static { $this->value = $v; return $this; }

    public function getDisplayValue(): string
    {
        if ($this->value === null) {
            return '—';
        }
        if ($this->definition->getDataType() === 'bool') {
            return $this->value === '1' ? 'Да' : 'Нет';
        }

        return $this->value . ($this->definition->getUnit() ? ' ' . $this->definition->getUnit() : '');
    }
}
