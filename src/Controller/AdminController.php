<?php

namespace App\Controller;

use App\Entity\IntegrationEvent;
use App\Entity\Notification;
use App\Entity\SystemError;
use App\Entity\User;
use App\Entity\ValueMapping;
use App\Enum\AssetStatus;
use App\Form\UserFormType;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use App\Security\PasswordPolicy;
use App\Service\AuditLogger;
use App\Service\NotificationService;
use App\Util\Paginator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly AuditLogger $audit)
    {
    }

    private function csrf(string $id, Request $r): void
    {
        if (!$this->isCsrfTokenValid($id, $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    #[Route('/users', name: 'admin_users')]
    public function users(UserRepository $repo): Response
    {
        return $this->render('admin/users.html.twig', ['users' => $repo->findBy([], ['fullName' => 'ASC'])]);
    }

    #[Route('/users/new', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function newUser(Request $r, UserPasswordHasherInterface $hasher): Response
    {
        return $this->userForm(new User(), $r, $hasher, true);
    }

    #[Route('/users/{id}/edit', name: 'admin_user_edit', methods: ['GET', 'POST'])]
    public function editUser(User $u, Request $r, UserPasswordHasherInterface $hasher): Response
    {
        return $this->userForm($u, $r, $hasher, false);
    }

    private function userForm(User $u, Request $r, UserPasswordHasherInterface $hasher, bool $isNew): Response
    {
        $before = ['roles' => $u->getStoredRoles(), 'active' => $u->isActive(), 'username' => $u->getUsername()];
        $form = $this->createForm(UserFormType::class, $u);
        $form->handleRequest($r);
        if ($form->isSubmitted() && $form->isValid()) {
            $tmp = null;
            if ($isNew) {
                $tmp = PasswordPolicy::generate();
                $u->setPassword($hasher->hashPassword($u, $tmp))->setMustChangePassword(true);
            }
            if ($u === $this->getUser() && !in_array('ROLE_ADMIN', $u->getStoredRoles(), true)) {
                $this->addFlash('error', 'Нельзя снять с себя роль администратора');

                return $this->redirectToRoute('admin_users');
            }
            $this->em->persist($u);
            $this->em->flush();
            [$old, $new] = AuditLogger::diff($isNew ? [] : $before, ['roles' => $u->getStoredRoles(), 'active' => $u->isActive(), 'username' => $u->getUsername()]);
            $this->audit->log($isNew ? 'user.create' : 'user.update', ['objectType' => 'user', 'objectId' => $u->getId()->toRfc4122(), 'objectLabel' => $u->getUsername(), 'old' => $old ?: null, 'new' => $new], true);
            if ($tmp) {
                $this->addFlash('warning', sprintf('Временный пароль для %s: %s — передайте пользователю защищенным способом; при первом входе потребуется смена.', $u->getUsername(), $tmp));
            }

            return $this->redirectToRoute('admin_users');
        }

        return $this->render('admin/user_form.html.twig', ['form' => $form, 'u' => $u, 'is_new' => $isNew]);
    }

    #[Route('/users/{id}/reset', name: 'admin_user_reset', methods: ['POST'])]
    public function resetPassword(User $u, Request $r, UserPasswordHasherInterface $hasher): Response
    {
        $this->csrf('reset' . $u->getId(), $r);
        $tmp = PasswordPolicy::generate();
        $u->setPassword($hasher->hashPassword($u, $tmp))->setMustChangePassword(true)->setLockedUntil(null)->setFailedLogins(0);
        $this->audit->log('user.password_reset', ['objectType' => 'user', 'objectId' => $u->getId()->toRfc4122(), 'objectLabel' => $u->getUsername()]);
        $this->em->flush();
        $this->addFlash('warning', sprintf('Новый временный пароль для %s: %s', $u->getUsername(), $tmp));

        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/{id}/unlock', name: 'admin_user_unlock', methods: ['POST'])]
    public function unlock(User $u, Request $r): Response
    {
        $this->csrf('reset' . $u->getId(), $r);
        $u->setLockedUntil(null)->setFailedLogins(0);
        $this->audit->log('user.unlock', ['objectType' => 'user', 'objectId' => $u->getId()->toRfc4122(), 'objectLabel' => $u->getUsername()]);
        $this->em->flush();

        return $this->redirectToRoute('admin_users');
    }

    #[Route('/audit', name: 'admin_audit')]
    public function audit(Request $r, AuditLogRepository $repo): Response
    {
        $f = array_filter($r->query->all(), fn ($v) => is_string($v) && $v !== '');
        unset($f['page']);

        return $this->render('admin/audit.html.twig', ['pager' => new Paginator($repo->searchQuery($f), max(1, $r->query->getInt('page', 1)), 100), 'f' => $f]);
    }

    #[Route('/errors', name: 'admin_errors')]
    public function errors(Request $r): Response
    {
        $qb = $this->em->getRepository(SystemError::class)->createQueryBuilder('e')->orderBy('e.id', 'DESC');

        return $this->render('admin/errors.html.twig', ['pager' => new Paginator($qb, max(1, $r->query->getInt('page', 1)), 50)]);
    }

    #[Route('/notifications', name: 'admin_notifications')]
    public function notifications(Request $r): Response
    {
        $qb = $this->em->getRepository(Notification::class)->createQueryBuilder('n')->orderBy('n.id', 'DESC');
        if ($st = $r->query->getString('status')) {
            $qb->where('n.status = :s')->setParameter('s', $st);
        }

        return $this->render('admin/notifications.html.twig', ['pager' => new Paginator($qb, max(1, $r->query->getInt('page', 1)), 50), 'status' => $st]);
    }

    #[Route('/notifications/{id}/resend', name: 'admin_notification_resend', methods: ['POST'])]
    public function resend(Notification $n, Request $r, NotificationService $service): Response
    {
        $this->csrf('resend' . $n->getId(), $r);
        $service->resend($n);
        $this->addFlash('success', 'Поставлено в очередь');

        return $this->redirectToRoute('admin_notifications');
    }

    #[Route('/integrations', name: 'admin_integrations')]
    public function integrations(Request $r, \App\Service\Integration\IntegrationOutbox $outbox): Response
    {
        $qb = $this->em->getRepository(IntegrationEvent::class)->createQueryBuilder('e')->orderBy('e.id', 'DESC');

        return $this->render('admin/integrations.html.twig', ['pager' => new Paginator($qb, max(1, $r->query->getInt('page', 1)), 50), 'enabled' => $outbox->isEnabled()]);
    }

    #[Route('/mappings', name: 'admin_mappings', methods: ['GET', 'POST'])]
    public function mappings(Request $r): Response
    {
        if ($r->isMethod('POST')) {
            $this->csrf('mappings', $r);
            $m = $this->em->find(ValueMapping::class, (int) $r->request->getString('delete'));
            if ($m) {
                $this->audit->log('import.mapping_delete', ['objectType' => 'value_mapping', 'old' => ['kind' => $m->getKind(), 'source' => $m->getSourceValue(), 'target' => $m->getTargetValue()]]);
                $this->em->remove($m);
                $this->em->flush();
            }

            return $this->redirectToRoute('admin_mappings');
        }

        return $this->render('admin/mappings.html.twig', [
            'mappings' => $this->em->getRepository(ValueMapping::class)->findBy([], ['kind' => 'ASC', 'sourceValue' => 'ASC']),
            'statuses' => array_combine(array_map(fn ($s) => $s->value, AssetStatus::cases()), array_map(fn ($s) => $s->label(), AssetStatus::cases())),
        ]);
    }
}
