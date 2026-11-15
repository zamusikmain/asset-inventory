<?php

namespace App\Service;

use App\Entity\HandoverDocument;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ссылка на подписание акта для сотрудника без учетной записи в системе
 * (п. 4: постоянный доступ получателю не обязателен). Токен — HMAC от id документа.
 */
class SignLinkService
{
    public function __construct(
        #[Autowire('%env(APP_SECRET)%')]
        private readonly string $secret,
    ) {
    }

    public function token(HandoverDocument $doc): string
    {
        return substr(hash_hmac('sha256', 'sign:' . $doc->getId()->toRfc4122() . ':' . $doc->getPdfSha256(), $this->secret), 0, 40);
    }

    public function isValid(HandoverDocument $doc, string $token): bool
    {
        return hash_equals($this->token($doc), $token);
    }
}
