<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Аудит входов и блокировка после неудачных попыток (п. 5.1 ТЗ).
 */
class LoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
        #[Autowire('%env(int:LOGIN_MAX_ATTEMPTS)%')]
        private readonly int $maxAttempts,
        #[Autowire('%env(int:LOGIN_LOCK_MINUTES)%')]
        private readonly int $lockMinutes,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onSuccess',
            LoginFailureEvent::class => 'onFailure',
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onSuccess(LoginSuccessEvent $e): void
    {
        $user = $e->getUser();
        if (!$user instanceof User) {
            return;
        }
        $user->setFailedLogins(0)->setLockedUntil(null)->setLastLoginAt(new \DateTimeImmutable());
        $e->getRequest()->getSession()->set('_last_activity', time());
        $this->audit->log('auth.login', ['user' => $user, 'objectType' => 'user', 'objectId' => $user->getId()->toRfc4122(), 'objectLabel' => $user->getUsername()]);
        $this->em->flush();
    }

    public function onFailure(LoginFailureEvent $e): void
    {
        $username = mb_strtolower(trim((string) $e->getRequest()->request->get('_username', '')));
        $user = $username !== '' ? $this->users->findOneBy(['username' => $username]) : null;
        $comment = $e->getException()->getMessageKey();

        if ($user && !$user->isLocked()) {
            $user->setFailedLogins($user->getFailedLogins() + 1);
            if ($user->getFailedLogins() >= $this->maxAttempts) {
                $user->setLockedUntil(new \DateTimeImmutable(sprintf('+%d minutes', $this->lockMinutes)));
                $user->setFailedLogins(0);
                $this->audit->log('auth.locked', ['user' => null, 'username' => $username, 'objectType' => 'user',
                    'objectId' => $user->getId()->toRfc4122(), 'comment' => sprintf('Блокировка на %d мин.', $this->lockMinutes)]);
            }
        }
        $this->audit->log('auth.login_failed', ['user' => null, 'username' => $username ?: null, 'comment' => $comment]);
        $this->em->flush();
    }

    public function onLogout(LogoutEvent $e): void
    {
        $user = $e->getToken()?->getUser();
        if ($user instanceof User) {
            $this->audit->log('auth.logout', ['user' => $user], true);
        }
    }
}
