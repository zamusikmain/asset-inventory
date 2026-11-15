<?php

namespace App\Entity;

use App\Enum\LocationType;
use App\Enum\RoomType;
use App\Repository\LocationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Иерархия: страна → город → бизнес-центр → офис → этаж → помещение (п. 15 ТЗ).
 */
#[ORM\Entity(repositoryClass: LocationRepository::class)]
#[ORM\Table(name: 'locations')]
#[ORM\Index(columns: ['type'])]
class Location
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Location $parent = null;

    /** @var Collection<int, Location> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: Location::class)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $children;

    #[ORM\Column(length: 20, enumType: LocationType::class)]
    private LocationType $type = LocationType::Room;

    #[ORM\Column(length: 20, nullable: true, enumType: RoomType::class)]
    private ?RoomType $roomType = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $code = null;

    #[ORM\Column]
    private bool $active = true;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getParent(): ?Location { return $this->parent; }
    public function setParent(?Location $v): static { $this->parent = $v; return $this; }
    /** @return Collection<int, Location> */
    public function getChildren(): Collection { return $this->children; }
    public function getType(): LocationType { return $this->type; }
    public function setType(LocationType $v): static { $this->type = $v; return $this; }
    public function getRoomType(): ?RoomType { return $this->roomType; }
    public function setRoomType(?RoomType $v): static { $this->roomType = $v; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = trim($v); return $this; }
    public function getCode(): ?string { return $this->code; }
    public function setCode(?string $v): static { $this->code = $v; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $v): static { $this->active = $v; return $this; }

    /** @return list<Location> от корня до текущего узла */
    public function getPath(): array
    {
        $path = [];
        for ($n = $this; $n !== null; $n = $n->parent) {
            array_unshift($path, $n);
        }

        return $path;
    }

    public function getPathString(string $sep = ' / ', bool $skipCountry = true): string
    {
        $names = [];
        foreach ($this->getPath() as $n) {
            if ($skipCountry && $n->type === LocationType::Country) {
                continue;
            }
            $names[] = $n->name;
        }

        return implode($sep, $names);
    }

    public function getAncestorOfType(LocationType $type): ?Location
    {
        for ($n = $this; $n !== null; $n = $n->parent) {
            if ($n->type === $type) {
                return $n;
            }
        }

        return null;
    }

    public function isDescendantOf(Location $other): bool
    {
        for ($n = $this; $n !== null; $n = $n->parent) {
            if ($n === $other || ($n->id !== null && $n->id === $other->id)) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return $this->getPathString();
    }
}
