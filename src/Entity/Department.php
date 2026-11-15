<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'departments')]
class Department
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $name = '';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $external1cId = null;

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = trim($v); return $this; }
    public function getExternal1cId(): ?string { return $this->external1cId; }
    public function setExternal1cId(?string $v): static { $this->external1cId = $v; return $this; }
    public function __toString(): string { return $this->name; }
}
