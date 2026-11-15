<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Подпись ЭЦП НУЦ РК (CMS/CAdES detached над PDF), полученная через NCALayer (п. 13.3 ТЗ).
 */
#[ORM\Entity]
#[ORM\Table(name: 'digital_signatures')]
class DigitalSignature
{
    public const VERIFY_VALID = 'valid';
    public const VERIFY_INVALID = 'invalid';
    public const VERIFY_PENDING = 'pending';

    public const VERIFY_LABELS = [
        self::VERIFY_VALID => 'Подпись действительна',
        self::VERIFY_INVALID => 'Подпись недействительна',
        self::VERIFY_PENDING => 'Требуется проверка',
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'signatures')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private HandoverDocument $document;

    /** Base64 CMS (CAdES-BES/-T) */
    #[ORM\Column(type: Types::TEXT)]
    private string $cms;

    /** SHA-256 подписанных данных (PDF) — для контроля целостности */
    #[ORM\Column(length: 64)]
    private string $signedDataSha256;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $certSubject = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $certIssuer = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $certSerial = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $iin = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $certValidFrom = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $certValidTo = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $signedAt = null;

    #[ORM\Column(length: 10)]
    private string $verificationStatus = self::VERIFY_PENDING;

    #[ORM\Column(type: Types::JSON)]
    private array $verificationDetails = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(HandoverDocument $document, string $cms, string $signedDataSha256)
    {
        $this->document = $document;
        $this->cms = $cms;
        $this->signedDataSha256 = $signedDataSha256;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getDocument(): HandoverDocument { return $this->document; }
    public function getCms(): string { return $this->cms; }
    public function getSignedDataSha256(): string { return $this->signedDataSha256; }
    public function getCertSubject(): ?string { return $this->certSubject; }
    public function getCertIssuer(): ?string { return $this->certIssuer; }
    public function getCertSerial(): ?string { return $this->certSerial; }
    public function getIin(): ?string { return $this->iin; }
    public function getCertValidFrom(): ?\DateTimeImmutable { return $this->certValidFrom; }
    public function getCertValidTo(): ?\DateTimeImmutable { return $this->certValidTo; }
    public function getSignedAt(): ?\DateTimeImmutable { return $this->signedAt; }
    public function getVerificationStatus(): string { return $this->verificationStatus; }
    public function getVerificationLabel(): string { return self::VERIFY_LABELS[$this->verificationStatus] ?? $this->verificationStatus; }
    public function getVerificationDetails(): array { return $this->verificationDetails; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function isValid(): bool { return $this->verificationStatus === self::VERIFY_VALID; }

    public function applyVerification(\App\Service\Signature\VerificationResult $r): void
    {
        $this->verificationStatus = $r->status;
        $this->verificationDetails = $r->details;
        $this->certSubject = $r->subject;
        $this->certIssuer = $r->issuer;
        $this->certSerial = $r->serial;
        $this->iin = $r->iin;
        $this->certValidFrom = $r->validFrom;
        $this->certValidTo = $r->validTo;
        $this->signedAt = $r->signedAt ?? new \DateTimeImmutable();
    }
}
