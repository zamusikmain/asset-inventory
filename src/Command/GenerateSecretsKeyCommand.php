<?php

namespace App\Command;

use App\Service\SecretVault;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Включение хранения секретов (п. 9 ТЗ) — только после согласования с ИБ.
 * Ключ хранится вне БД; его резервная копия делается отдельно от backup БД.
 */
#[AsCommand(name: 'app:secrets:generate-key', description: 'Сгенерировать ключ шифрования секретов (вне БД)')]
class GenerateSecretsKeyCommand extends Command
{
    public function __construct(#[Autowire('%env(SECRETS_KEY_FILE)%')] private readonly string $keyFile)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $io = new SymfonyStyle($in, $out);
        if ($this->keyFile === '') {
            $io->error('SECRETS_KEY_FILE не задан');

            return Command::FAILURE;
        }
        if (is_file($this->keyFile)) {
            $io->warning('Ключ уже существует: ' . $this->keyFile . '. Замена ключа сделает существующие секреты нечитаемыми.');

            return Command::FAILURE;
        }
        if (!is_dir(dirname($this->keyFile))) {
            mkdir(dirname($this->keyFile), 0700, true);
        }
        file_put_contents($this->keyFile, SecretVault::generateKey());
        chmod($this->keyFile, 0400);
        $io->success('Ключ создан: ' . $this->keyFile . '. Сохраните его копию в защищенном хранилище ОТДЕЛЬНО от резервных копий БД.');

        return Command::SUCCESS;
    }
}
