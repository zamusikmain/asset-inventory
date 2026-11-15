<?php

namespace App\EventSubscriber;

use App\Entity\SystemError;
use App\Exception\BusinessException;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Журнал технических ошибок + уведомление администратора (п. 19 ТЗ).
 * Пишется напрямую через DBAL: EntityManager после ошибки может быть закрыт.
 */
class ErrorJournalSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
        private readonly MailerInterface $mailer,
        #[Autowire('%env(ADMIN_ALERT_EMAIL)%')]
        private readonly string $adminEmail,
        #[Autowire('%env(MAIL_FROM)%')]
        private readonly string $mailFrom,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', -10]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        if ($e instanceof BusinessException || $e instanceof AccessDeniedException
            || ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500)) {
            return;
        }
        try {
            $this->db->insert('system_errors', [
                'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'level' => 'error',
                'message' => mb_substr($e->getMessage(), 0, 10000),
                'exception_class' => $e::class,
                'trace' => mb_substr($e->getTraceAsString(), 0, 20000),
                'request_uri' => mb_substr($event->getRequest()->getRequestUri(), 0, 2000),
                'username' => $this->security->getUser()?->getUserIdentifier(),
                'notified' => $this->adminEmail !== '' ? 1 : 0,
            ]);
            if ($this->adminEmail !== '') {
                $this->mailer->send((new Email())
                    ->from($this->mailFrom)->to($this->adminEmail)
                    ->subject('[Учет оборудования] Техническая ошибка: ' . mb_strimwidth($e->getMessage(), 0, 80, '…'))
                    ->text(sprintf("%s\n%s\nURI: %s\n\n%s", $e::class, $e->getMessage(), $event->getRequest()->getRequestUri(), $e->getTraceAsString())));
            }
        } catch (\Throwable $inner) {
            $this->logger->critical('Не удалось записать ошибку в журнал: ' . $inner->getMessage());
        }
    }
}
