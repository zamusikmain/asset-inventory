<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * План этажа/офиса (п. 15 ТЗ): SVG (предпочтительно) или PNG ≥ 2000 px.
 * regions — области плана, связанные с помещениями, в процентах от размера:
 * [{locationId: int, x: float, y: float, w: float, h: float}]
 */
#[ORM\Entity]
#[ORM\Table(name: 'office_plans')]
class OfficePlan
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Location $location;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $storagePath;

    #[ORM\Column(length: 50)]
    private string $mimeType;

    #[ORM\Column]
    private int $width = 0;

    #[ORM\Column]
    private int $height = 0;

    #[ORM\Column(type: Types::JSON)]
    private array $regions = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Location $location, string $name, string $storagePath, string $mimeType)
    {
        $this->location = $location;
        $this->name = $name;
        $this->storagePath = $storagePath;
        $this->mimeType = $mimeType;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getLocation(): Location { return $this->location; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getStoragePath(): string { return $this->storagePath; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }

    public function setDimensions(int $w, int $h): static
    {
        $this->width = $w;
        $this->height = $h;

        return $this;
    }

    public function getRegions(): array { return $this->regions; }

    public function setRegions(array $v): static
    {
        $this->regions = $v;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
