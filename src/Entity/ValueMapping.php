<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Справочник соответствий для импорта: исходное значение Excel → значение системы.
 * kind: status | location | employee.
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_value_mappings')]
#[ORM\UniqueConstraint(columns: ['kind', 'source_value'])]
class ValueMapping
{
    public const KIND_STATUS = 'status';
    public const KIND_LOCATION = 'location';
    public const KIND_EMPLOYEE = 'employee';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $kind;

    /** Нормализованное (lowercase, trim) исходное значение */
    #[ORM\Column(length: 255)]
    private string $sourceValue;

    /** Значение системы: код статуса / id локации / uuid сотрудника */
    #[ORM\Column(length: 64)]
    private string $targetValue;

    public function __construct(string $kind, string $sourceValue, string $targetValue)
    {
        $this->kind = $kind;
        $this->sourceValue = self::key($sourceValue);
        $this->targetValue = $targetValue;
    }

    public static function key(string $v): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $v)));
    }

    public function getId(): ?int { return $this->id; }
    public function getKind(): string { return $this->kind; }
    public function getSourceValue(): string { return $this->sourceValue; }
    public function getTargetValue(): string { return $this->targetValue; }
    public function setTargetValue(string $v): static { $this->targetValue = $v; return $this; }
}
