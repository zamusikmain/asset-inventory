<?php

namespace App\Command;

use App\Entity\IntegrationEvent;
use App\Service\Integration\IntegrationOutbox;
use App\Service\Integration\OneCConnectorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Доставка событий outbox в 1С (cron). Ошибки 1С не влияют на основное приложение.
 */
#[AsCommand(name: 'app:integration:process', description: 'Отправить накопленные события интеграции в 1С')]
class ProcessIntegrationsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OneCConnectorInterface $connector,
        private readonly IntegrationOutbox $outbox,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, '', 200)->addOption('max-attempts', null, InputOption::VALUE_REQUIRED, '', 10);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $io = new SymfonyStyle($in, $out);
        if (!$this->outbox->isEnabled()) {
            $io->note('Интеграция с 1С отключена (INTEGRATION_1C_ENABLED=0)');

            return Command::SUCCESS;
        }
        $events = $this->em->createQuery('SELECT e FROM App\Entity\IntegrationEvent e WHERE e.system = :s AND e.direction = :d AND e.status IN (:st) AND e.attempts < :max ORDER BY e.id')
            ->setParameters(['s' => '1c', 'd' => 'out', 'st' => [IntegrationEvent::STATUS_PENDING, IntegrationEvent::STATUS_FAILED], 'max' => (int) $in->getOption('max-attempts')])
            ->setMaxResults((int) $in->getOption('limit'))->getResult();
        $ok = $fail = 0;
        foreach ($events as $e) {
            try {
                $this->connector->send($e);
                $e->markProcessed(true);
                ++$ok;
            } catch (\Throwable $ex) {
                $e->markProcessed(false, $ex->getMessage());
                ++$fail;
            }
        }
        $this->em->flush();
        $io->success(sprintf('Коннектор %s: отправлено %d, ошибок %d', $this->connector->name(), $ok, $fail));

        return Command::SUCCESS;
    }
}
