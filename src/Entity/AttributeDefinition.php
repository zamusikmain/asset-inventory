<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Настраиваемый атрибут категории (attribute_definitions, п. 6.2 ТЗ):
 * RAM, SSD, CPU, диагональ, срок лицензии и т.п.
 */
#[ORM\Entity]
#[ORM\Table(name: 'attribute_definitions')]
#[ORM\UniqueConstraint(columns: ['asset_type_id', 'code'])]
class AttributeDefinition
{
    public const TYPES = [
        'Строка' => 'string',
        'Целое число' => 'int',
        'Дробное число' => 'decimal',
        'Дата' => 'date',
        'Да/нет' => 'bool',
        'Список' => 'select',
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'attributes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AssetType $assetType = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z][a-z0-9_]*$/', message: 'Код: латиница в нижнем регистре, цифры, _')]
    private string $code = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private string $label = '';

    #[ORM\Column(length: 20)]
    private string $dataType = 'string';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $unit = null;

    /** Варианты для типа "Список" */
    #[ORM\Column(type: Types::JSON)]
    private array $options = [];

    #[ORM\Column]
    private bool $required = false;

    #[ORM\Column]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }
    public function getAssetType(): ?AssetType { return $this->assetType; }
    public function setAssetType(?AssetType $v): static { $this->assetType = $v; return $this; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $v): static { $this->code = $v; return $this; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $v): static { $this->label = $v; return $this; }
    public function getDataType(): string { return $this->dataType; }
    public function setDataType(string $v): static { $this->dataType = $v; return $this; }
    public function getUnit(): ?string { return $this->unit; }
    public function setUnit(?string $v): static { $this->unit = $v; return $this; }
    public function getOptions(): array { return $this->options; }
    public function setOptions(array $v): static { $this->options = array_values(array_filter(array_map('trim', $v))); return $this; }
    public function getOptionsText(): string { return implode("\n", $this->options); }
    public function setOptionsText(?string $v): static { return $this->setOptions(preg_split('/\R/', (string) $v)); }
    public function isRequired(): bool { return $this->required; }
    public function setRequired(bool $v): static { $this->required = $v; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): static { $this->sortOrder = $v; return $this; }

    /** Проверка и нормализация значения; возвращает null при пустом. */
    public function normalize(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $raw = trim((string) $raw);

        return match ($this->dataType) {
            'int' => filter_var($raw, FILTER_VALIDATE_INT) !== false ? (string) (int) $raw
                : throw new \InvalidArgumentException(sprintf('«%s»: ожидается целое число', $this->label)),
            'decimal' => is_numeric(str_replace(',', '.', $raw)) ? str_replace(',', '.', $raw)
                : throw new \InvalidArgumentException(sprintf('«%s»: ожидается число', $this->label)),
            'date' => (\DateTimeImmutable::createFromFormat('!Y-m-d', $raw) ?: throw new \InvalidArgumentException(sprintf('«%s»: ожидается дата ГГГГ-ММ-ДД', $this->label)))->format('Y-m-d'),
            'bool' => in_array(mb_strtolower($raw), ['1', 'true', 'да', 'yes', 'on'], true) ? '1' : '0',
            'select' => in_array($raw, $this->options, true) ? $raw
                : throw new \InvalidArgumentException(sprintf('«%s»: недопустимое значение', $this->label)),
            default => $raw,
        };
    }
}
