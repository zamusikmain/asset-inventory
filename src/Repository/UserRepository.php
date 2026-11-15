<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/** @extends ServiceEntityRepository<User> */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException();
        }
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    /** @return list<User> */
    public function findActiveWithRole(string $role): array
    {
        return array_values(array_filter(
            $this->findBy(['active' => true], ['fullName' => 'ASC']),
            fn (User $u) => in_array($role, $u->getRoles(), true) || in_array('ROLE_ADMIN', $u->getRoles(), true),
        ));
    }
}
