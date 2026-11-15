<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Запись журнала аудита (п. 18.1 ТЗ). Автоматически дополняет пользователя,
 * IP-адрес, идентификатор сессии и источник.
 */
class AuditLogger
{
    private ?string $sourceOverride = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requests,
        private readonly Security $security,
    ) {
    }

    /** Источник для операций вне HTTP-запроса (cli, import, system). */
    public function setSource(?string $source): void
    {
        $this->sourceOverride = $source;
    }

    /**
     * @param array{objectType?:string, objectId?:string, objectLabel?:string, old?:array|null, new?:array|null,
     *              comment?:string|null, document?:string|null, user?:User|null, username?:string|null, source?:string} $ctx
     */
    public function log(string $action, array $ctx = [], bool $flush = false): AuditLog
    {
        $request = $this->requests->getMainRequest();
        $user = $ctx['user'] ?? $this->security->getUser();

        $source = $ctx['source'] ?? $this->sourceOverride;
        if (!$source) {
            $source = 'web';
            if ($request && str_starts_with($request->getPathInfo(), '/api/')) {
                $source = $request->headers->get('X-Client') === 'pwa' ? 'pwa' : 'api';
            } elseif (!$request) {
                $source = 'cli';
            }
        }

        $entry = (new AuditLog($action))->fill([
            'userId' => $user instanceof User ? $user->getId()->toRfc4122() : null,
            'username' => $ctx['username'] ?? ($user instanceof User ? $user->getUsername() : $user?->getUserIdentifier()),
            'objectType' => $ctx['objectType'] ?? null,
            'objectId' => isset($ctx['objectId']) ? (string) $ctx['objectId'] : null,
            'objectLabel' => isset($ctx['objectLabel']) ? mb_substr($ctx['objectLabel'], 0, 500) : null,
            'oldValues' => $ctx['old'] ?? null,
            'newValues' => $ctx['new'] ?? null,
            'ip' => $request?->getClientIp(),
            'sessionId' => $request && $request->hasSession(false) && $request->getSession()->isStarted()
                ? substr(hash('sha256', $request->getSession()->getId()), 0, 32) : null,
            'source' => $source,
            'comment' => $ctx['comment'] ?? null,
            'relatedDocument' => $ctx['document'] ?? null,
        ]);

        $this->em->persist($entry);
        if ($flush) {
            $this->em->flush();
        }

        return $entry;
    }

    /**
     * Разница двух снимков: возвращает [old, new] только по изменившимся полям.
     *
     * @return array{0: array, 1: array}
     */
    public static function diff(array $before, array $after): array
    {
        $old = $new = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $k) {
            $b = $before[$k] ?? null;
            $a = $after[$k] ?? null;
            if ($b !== $a) {
                $old[$k] = $b;
                $new[$k] = $a;
            }
        }

        return [$old, $new];
    }
}
