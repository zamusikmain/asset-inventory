<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Вложение (asset_documents, п. 18.2 ТЗ). Хранится вне public-каталога.
 * Привязывается к активу или лицензии.
 */
#[ORM\Entity]
#[ORM\Table(name: 'asset_documents')]
class StoredFile
{
    public const CATEGORIES = [
        'Фотография' => 'photo',
        'Акт' => 'act',
        'Гарантия' => 'warranty',
        'Чек' => 'receipt',
        'Счет' => 'invoice',
        'Технический документ' => 'technical',
        'Иной документ' => 'other',
    ];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Asset $asset = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?License $license = null;

    #[ORM\Column(length: 20)]
    private string $category = 'other';

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 255)]
    private string $storagePath;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column]
    private int $size;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $uploadedAt;

    public function __construct(string $originalName, string $storagePath, string $mimeType, int $size, string $sha256)
    {
        $this->id = Uuid::v7();
        $this->originalName = $originalName;
        $this->storagePath = $storagePath;
        $this->mimeType = $mimeType;
        $this->size = $size;
        $this->sha256 = $sha256;
        $this->uploadedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getAsset(): ?Asset { return $this->asset; }
    public function setAsset(?Asset $v): static { $this->asset = $v; return $this; }
    public function getLicense(): ?License { return $this->license; }
    public function setLicense(?License $v): static { $this->license = $v; return $this; }
    public function getCategory(): string { return $this->category; }
    public function setCategory(string $v): static { $this->category = $v; return $this; }
    public function getCategoryLabel(): string { return array_search($this->category, self::CATEGORIES, true) ?: $this->category; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getStoragePath(): string { return $this->storagePath; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getSize(): int { return $this->size; }
    public function getSha256(): string { return $this->sha256; }
    public function getUploadedBy(): ?User { return $this->uploadedBy; }
    public function setUploadedBy(?User $v): static { $this->uploadedBy = $v; return $this; }
    public function getUploadedAt(): \DateTimeImmutable { return $this->uploadedAt; }
}
