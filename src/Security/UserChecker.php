<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }
        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('Учетная запись отключена. Обратитесь к администратору.');
        }
        if ($user->isLocked()) {
            throw new CustomUserMessageAccountStatusException(sprintf(
                'Учетная запись временно заблокирована после неудачных попыток входа до %s.',
                $user->getLockedUntil()->setTimezone(new \DateTimeZone('Asia/Almaty'))->format('H:i'),
            ));
        }
    }

    public function checkPostAuth(UserInterface $user, ?\Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token = null): void
    {
    }
}
