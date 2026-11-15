<?php

namespace App\Service\Signature;

use App\Entity\DigitalSignature;

final class VerificationResult
{
    public function __construct(
        public string $status = DigitalSignature::VERIFY_PENDING,
        public array $details = [],
        public ?string $subject = null,
        public ?string $issuer = null,
        public ?string $serial = null,
        public ?string $iin = null,
        public ?\DateTimeImmutable $validFrom = null,
        public ?\DateTimeImmutable $validTo = null,
        public ?\DateTimeImmutable $signedAt = null,
    ) {
    }

    public static function extractIin(?string $subject): ?string
    {
        if ($subject && preg_match('/IIN(\d{12})/', $subject, $m)) {
            return $m[1];
        }

        return null;
    }
}
