<?php

namespace App\Command;

use App\Entity\HandoverDocument;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Обработка неподписанных актов с истекшим сроком (п. 13.3: порядок обработки
 * неподписанного документа). Закрепление сохраняется, акт помечается «Срок истек»,
 * IT видит его в списке и переоформляет.
 */
#[AsCommand(name: 'app:signatures:expire', description: 'Пометить акты с истекшим сроком подписания')]
class ExpireSignaturesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $this->audit->setSource('system');
        $docs = $this->em->createQuery('SELECT d FROM App\Entity\HandoverDocument d WHERE d.status = :s AND d.signDeadline < :today')
            ->setParameter('s', HandoverDocument::STATUS_AWAITING)->setParameter('today', new \DateTimeImmutable('today'))->getResult();
        foreach ($docs as $d) {
            $d->setStatus(HandoverDocument::STATUS_EXPIRED);
            $this->audit->log('signature.expired', ['objectType' => 'handover_document', 'objectId' => $d->getId()->toRfc4122(), 'objectLabel' => $d->getNumber(), 'document' => $d->getNumber()]);
        }
        $this->em->flush();
        (new SymfonyStyle($in, $out))->success(sprintf('Помечено актов: %d', count($docs)));

        return Command::SUCCESS;
    }
}
