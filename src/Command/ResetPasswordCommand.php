<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Security\PasswordPolicy;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:password', description: 'Сбросить пароль пользователя и снять блокировку')]
class ResetPasswordCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED)
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Новый пароль (иначе — временный, со сменой при входе)');
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $io = new SymfonyStyle($in, $out);
        $u = $this->users->findOneBy(['username' => mb_strtolower($in->getArgument('username'))]);
        if (!$u) {
            $io->error('Пользователь не найден');

            return Command::FAILURE;
        }
        $given = $in->getOption('password');
        if ($given && ($v = PasswordPolicy::violations($given, $u->getUsername()))) {
            $io->error('Пароль не соответствует политике: ' . implode(', ', $v));

            return Command::FAILURE;
        }
        $password = $given ?: PasswordPolicy::generate();
        $u->setPassword($this->hasher->hashPassword($u, $password))
            ->setMustChangePassword(!$given)->setLockedUntil(null)->setFailedLogins(0)->setActive(true);
        $this->audit->log('user.password_reset', ['objectType' => 'user', 'objectId' => $u->getId()->toRfc4122(), 'objectLabel' => $u->getUsername(), 'source' => 'cli']);
        $this->em->flush();
        $io->success($given ? 'Пароль установлен, блокировка снята.' : sprintf('Временный пароль: %s — смена при первом входе.', $password));

        return Command::SUCCESS;
    }
}
