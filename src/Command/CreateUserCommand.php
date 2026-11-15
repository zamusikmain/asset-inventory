<?php

namespace App\Command;

use App\Entity\User;
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

#[AsCommand(name: 'app:user:create', description: 'Создать учетную запись (первый администратор и т.д.)')]
class CreateUserCommand extends Command
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
            ->addArgument('fullName', InputArgument::OPTIONAL, '', '')
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'ROLE_ADMIN, ROLE_IT, ...', ['ROLE_ADMIN'])
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Пароль (иначе — сгенерировать временный)');
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $io = new SymfonyStyle($in, $out);
        $username = mb_strtolower($in->getArgument('username'));
        if ($this->users->findOneBy(['username' => $username])) {
            $io->error('Пользователь уже существует');

            return Command::FAILURE;
        }
        $password = $in->getOption('password') ?: PasswordPolicy::generate();
        if ($in->getOption('password') && ($v = PasswordPolicy::violations($password, $username))) {
            $io->error('Пароль не соответствует политике: ' . implode(', ', $v));

            return Command::FAILURE;
        }
        $u = (new User())->setUsername($username)->setFullName($in->getArgument('fullName') ?: $username)->setRoles($in->getOption('role'));
        $u->setPassword($this->hasher->hashPassword($u, $password))->setMustChangePassword(true);
        $this->em->persist($u);
        $this->audit->log('user.create', ['objectType' => 'user', 'objectId' => $u->getId()->toRfc4122(), 'objectLabel' => $username, 'source' => 'cli', 'new' => ['roles' => $u->getStoredRoles()]]);
        $this->em->flush();
        $io->success(sprintf('Создан %s (%s). Временный пароль: %s — смена при первом входе.', $username, implode(', ', $u->getStoredRoles()), $password));

        return Command::SUCCESS;
    }
}
