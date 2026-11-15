<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\PasswordPolicy;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $auth, Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $auth->getLastUsername(),
            'error' => $auth->getLastAuthenticationError(),
            'expired' => $request->query->getBoolean('expired'),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Обрабатывается firewall');
    }

    #[Route('/password', name: 'app_change_password', methods: ['GET', 'POST'])]
    public function changePassword(Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('change_password', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $current = $request->request->getString('current');
            $new = $request->request->getString('new');
            $repeat = $request->request->getString('repeat');

            if (!$hasher->isPasswordValid($user, $current)) {
                $errors[] = 'Текущий пароль указан неверно';
            }
            if ($new !== $repeat) {
                $errors[] = 'Новый пароль и подтверждение не совпадают';
            }
            if ($new === $current) {
                $errors[] = 'Новый пароль должен отличаться от текущего';
            }
            if ($v = PasswordPolicy::violations($new, $user->getUsername())) {
                $errors[] = 'Требования к паролю: ' . implode(', ', $v);
            }
            if (!$errors) {
                $user->setPassword($hasher->hashPassword($user, $new));
                $user->setMustChangePassword(false)->setPasswordChangedAt(new \DateTimeImmutable());
                $audit->log('auth.password_change', ['objectType' => 'user', 'objectId' => $user->getId()->toRfc4122(), 'objectLabel' => $user->getUsername()]);
                $em->flush();
                $this->addFlash('success', 'Пароль изменен');

                return $this->redirectToRoute('app_dashboard');
            }
        }

        return $this->render('security/change_password.html.twig', ['errors' => $errors, 'forced' => $user->isMustChangePassword(), 'min' => PasswordPolicy::MIN_LENGTH]);
    }
}
