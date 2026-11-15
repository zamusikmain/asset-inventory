<?php

namespace App\MessageHandler;

use App\Entity\Notification;
use App\Message\SendNotification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
class SendNotificationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransportInterface $transport,
        private readonly string $mailFrom,
    ) {
    }

    public function __invoke(SendNotification $msg): void
    {
        $n = $this->em->find(Notification::class, $msg->notificationId);
        if (!$n || $n->getStatus() === Notification::STATUS_SENT || !$n->getRecipient()) {
            return;
        }
        try {
            $this->transport->send((new Email())
                ->from(Address::create($this->mailFrom))
                ->to($n->getRecipient())
                ->subject($n->getSubject())
                ->html($n->getBodyHtml()));
            $n->markSent();
            $this->em->flush();
        } catch (\Throwable $e) {
            $n->markFailed($e->getMessage());
            $this->em->flush();
            throw $e; // повтор по retry_strategy
        }
    }
}
