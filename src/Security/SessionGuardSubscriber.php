<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * 1) Автоматическая блокировка сессии после периода бездействия (п. 19 ТЗ).
 * 2) Обязательная смена первоначального пароля (п. 5.1 ТЗ).
 */
class SessionGuardSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%env(int:SESSION_IDLE_TIMEOUT)%')]
        private readonly int $idleTimeout,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // после firewall (priority 8)
        return [KernelEvents::REQUEST => ['onRequest', 7]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $user = $this->security->getUser();
        if (!$user instanceof User || !$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        $isApi = str_starts_with($request->getPathInfo(), '/api/');

        $last = (int) $session->get('_last_activity', time());
        // Фоновая синхронизация PWA не продлевает сессию оператора, но и не обрывает её
        if ($this->idleTimeout > 0 && time() - $last > $this->idleTimeout) {
            $this->security->logout(false);
            $session->invalidate();
            $event->setResponse($isApi
                ? new JsonResponse(['error' => 'session_expired', 'message' => 'Сессия истекла из-за бездействия'], 401)
                : new RedirectResponse($this->urls->generate('app_login', ['expired' => 1])));

            return;
        }
        if (!$request->headers->has('X-Background-Sync')) {
            $session->set('_last_activity', time());
        }

        $route = (string) $request->attributes->get('_route');
        if ($user->isMustChangePassword() && !in_array($route, ['app_change_password', 'app_logout'], true)
            && !str_starts_with($route, '_')) {
            $event->setResponse($isApi
                ? new JsonResponse(['error' => 'password_change_required'], 403)
                : new RedirectResponse($this->urls->generate('app_change_password')));
        }
    }
}
