<?php

namespace App\EventSubscriber;

use App\Exception\BusinessException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Нарушение бизнес-правила: для API — JSON 422, для веба — flash-сообщение и возврат назад.
 */
class BusinessExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 10]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        if (!$e instanceof BusinessException) {
            return;
        }
        $request = $event->getRequest();
        if (str_starts_with($request->getPathInfo(), '/api/') || $request->getPreferredFormat() === 'json') {
            $event->setResponse(new JsonResponse(['error' => 'business_rule', 'message' => $e->getMessage()], 422));

            return;
        }
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add('error', $e->getMessage());
        }
        $back = $request->headers->get('referer') ?: '/';
        $event->setResponse(new RedirectResponse($back));
    }
}
