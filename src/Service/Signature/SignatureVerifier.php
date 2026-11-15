<?php

namespace App\Service\Signature;

use App\Entity\DigitalSignature;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Проверка CMS-подписи (CAdES, detached) над PDF-актом — прототип п. 13.3 ТЗ.
 *
 * Режимы:
 *  1) NCANODE_URL задан — полная проверка через NCANode (подпись, цепочка НУЦ РК,
 *     срок действия, отзыв по OCSP/CRL). Рекомендуется для PROD.
 *  2) Иначе — базовая проверка средствами OpenSSL: целостность подписи и срок
 *     действия сертификата. Цепочка и отзыв НЕ проверяются; для ГОСТ-сертификатов
 *     OpenSSL проверку выполнить не может → статус «Требуется проверка».
 */
class SignatureVerifier
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(NCANODE_URL)%')]
        private readonly string $ncanodeUrl,
        #[Autowire('%env(bool:default::SIGNATURE_ACCEPT_BASIC)%')]
        private readonly ?bool $acceptBasic = false,
    ) {
    }

    public function mode(): string
    {
        return $this->ncanodeUrl !== '' ? 'ncanode' : 'basic';
    }

    public function verify(string $cmsBase64, string $data): VerificationResult
    {
        $cmsBase64 = preg_replace('/\s+/', '', $cmsBase64);
        if (base64_decode($cmsBase64, true) === false) {
            return new VerificationResult(DigitalSignature::VERIFY_INVALID, ['error' => 'Подпись не в формате Base64']);
        }

        return $this->ncanodeUrl !== '' ? $this->verifyNcaNode($cmsBase64, $data) : $this->verifyBasic($cmsBase64, $data);
    }

    private function verifyNcaNode(string $cms, string $data): VerificationResult
    {
        try {
            $resp = $this->http->request('POST', rtrim($this->ncanodeUrl, '/') . '/cms/verify', [
                'json' => ['cms' => $cms, 'data' => base64_encode($data), 'revocationCheck' => ['OCSP'], 'detached' => true],
                'timeout' => 20,
            ])->toArray(false);
        } catch (\Throwable $e) {
            return new VerificationResult(DigitalSignature::VERIFY_PENDING, ['error' => 'NCANode недоступен: ' . $e->getMessage()]);
        }

        $signer = $resp['signers'][0] ?? [];
        $cert = $signer['certificates'][0] ?? [];
        $subject = $cert['subject']['dn'] ?? ($cert['subjectDn'] ?? null);
        $r = new VerificationResult(
            ($resp['valid'] ?? false) ? DigitalSignature::VERIFY_VALID : DigitalSignature::VERIFY_INVALID,
            ['mode' => 'ncanode', 'response' => $resp],
            $subject,
            $cert['issuer']['dn'] ?? null,
            $cert['serialNumber'] ?? null,
            $cert['subject']['iin'] ?? VerificationResult::extractIin($subject),
            isset($cert['notBefore']) ? new \DateTimeImmutable($cert['notBefore']) : null,
            isset($cert['notAfter']) ? new \DateTimeImmutable($cert['notAfter']) : null,
            isset($signer['tsp']['genTime']) ? new \DateTimeImmutable($signer['tsp']['genTime']) : null,
        );

        return $r;
    }

    private function verifyBasic(string $cms, string $data): VerificationResult
    {
        $tmp = sys_get_temp_dir();
        $cmsFile = tempnam($tmp, 'cms');
        $dataFile = tempnam($tmp, 'dat');
        $certFile = tempnam($tmp, 'crt');
        try {
            file_put_contents($cmsFile, "-----BEGIN CMS-----\n" . chunk_split($cms, 64, "\n") . "-----END CMS-----\n");
            file_put_contents($dataFile, $data);

            $ok = @openssl_cms_verify($cmsFile, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED, $certFile, [], null, null, $dataFile, null, OPENSSL_ENCODING_PEM);
            $errors = [];
            while ($msg = openssl_error_string()) {
                $errors[] = $msg;
            }

            $r = new VerificationResult(details: ['mode' => 'basic']);
            $pem = (string) @file_get_contents($certFile);
            if ($pem !== '' && ($x = @openssl_x509_parse($pem))) {
                $r->subject = self::dn($x['subject'] ?? []);
                $r->issuer = self::dn($x['issuer'] ?? []);
                $r->serial = $x['serialNumberHex'] ?? null;
                $r->iin = VerificationResult::extractIin($r->subject) ?? (isset($x['subject']['serialNumber']) ? VerificationResult::extractIin((string) $x['subject']['serialNumber']) : null);
                $r->validFrom = (new \DateTimeImmutable())->setTimestamp($x['validFrom_time_t']);
                $r->validTo = (new \DateTimeImmutable())->setTimestamp($x['validTo_time_t']);
            }

            if ($ok !== true) {
                $gost = str_contains(implode(' ', $errors), 'unknown') || str_contains(implode(' ', $errors), 'unsupported');
                $r->status = $gost ? DigitalSignature::VERIFY_PENDING : DigitalSignature::VERIFY_INVALID;
                $r->details['errors'] = $errors;
                $r->details['note'] = $gost
                    ? 'Алгоритм подписи (ГОСТ) не поддерживается OpenSSL — требуется проверка через NCANode'
                    : 'Подпись не соответствует документу';

                return $r;
            }

            $now = new \DateTimeImmutable();
            if ($r->validTo && ($r->validTo < $now || $r->validFrom > $now)) {
                $r->status = DigitalSignature::VERIFY_INVALID;
                $r->details['note'] = 'Срок действия сертификата истек или еще не наступил';

                return $r;
            }

            $r->details['note'] = 'Подпись соответствует документу; цепочка НУЦ РК и отзыв сертификата не проверялись (базовый режим)';
            $r->status = $this->acceptBasic ? DigitalSignature::VERIFY_VALID : DigitalSignature::VERIFY_PENDING;

            return $r;
        } finally {
            @unlink($cmsFile);
            @unlink($dataFile);
            @unlink($certFile);
        }
    }

    private static function dn(array $parts): string
    {
        $out = [];
        foreach ($parts as $k => $v) {
            $out[] = $k . '=' . (is_array($v) ? implode('+', $v) : $v);
        }

        return implode(', ', $out);
    }
}
