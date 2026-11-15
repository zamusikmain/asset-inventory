<?php

namespace App\Service;

use App\Entity\Employee;
use App\Entity\HandoverDocument;
use App\Entity\Notification;
use App\Message\SendNotification;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Уведомление сотрудника (п. 14 ТЗ): операция, дата, полный текущий перечень,
 * добавленные и возвращенные позиции, ссылка на акт, статус подписи, контакты IT.
 */
class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly Environment $twig,
        private readonly AssetRepository $assets,
        private readonly UrlGeneratorInterface $urls,
        private readonly SignLinkService $signLinks,
        private readonly string $itContacts,
    ) {
    }

    /**
     * @param list<array> $added    позиции (снимки) выданные
     * @param list<array> $returned позиции возвращенные
     */
    public function notifyHoldings(Employee $employee, string $operation, ?HandoverDocument $doc, array $added = [], array $returned = []): Notification
    {
        $current = array_map([HandoverService::class, 'itemSnapshot'], $this->assets->findAssignedTo($employee));
        $signUrl = $doc ? $this->urls->generate('sign_document', [
            'id' => $doc->getId()->toRfc4122(), 'token' => $this->signLinks->token($doc),
        ], UrlGeneratorInterface::ABSOLUTE_URL) : null;

        $subject = sprintf('%s — %s', $operation, $doc ? 'акт ' . $doc->getNumber() : 'изменение закрепления оборудования');
        $html = $this->twig->render('email/holdings.html.twig', [
            'employee' => $employee,
            'operation' => $operation,
            'date' => new \DateTimeImmutable(),
            'doc' => $doc,
            'sign_url' => $signUrl,
            'current' => $current,
            'added' => $added,
            'returned' => $returned,
            'it_contacts' => $this->itContacts,
        ]);

        $n = new Notification($employee, $employee->getEmail(), $subject, $html);
        $n->setDocument($doc);
        $this->em->persist($n);
        $this->em->flush();

        if ($n->getStatus() === Notification::STATUS_QUEUED) {
            try {
                $this->bus->dispatch(new SendNotification($n->getId()));
            } catch (\Throwable $e) {
                // недоступность очереди/SMTP не отменяет операцию; повтор — из «Уведомления»
                $n->markFailed('Ошибка постановки в очередь: ' . $e->getMessage());
                $this->em->flush();
            }
        }

        return $n;
    }

    public function resend(Notification $n): void
    {
        if ($n->getRecipient()) {
            $this->bus->dispatch(new SendNotification($n->getId()));
        }
    }
}
