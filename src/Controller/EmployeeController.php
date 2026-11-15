<?php

namespace App\Controller;

use App\Entity\AssetAssignment;
use App\Entity\Department;
use App\Entity\Employee;
use App\Entity\HandoverDocument;
use App\Entity\LicenseAssignment;
use App\Entity\Notification;
use App\Form\EmployeeFormType;
use App\Repository\AssetRepository;
use App\Repository\EmployeeRepository;
use App\Service\AuditLogger;
use App\Service\NotificationService;
use App\Util\Paginator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/employees')]
#[IsGranted('ROLE_IT')]
class EmployeeController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly AuditLogger $audit)
    {
    }

    #[Route('', name: 'employee_index')]
    public function index(Request $r, EmployeeRepository $repo): Response
    {
        $q = trim($r->query->getString('q'));
        $status = $r->query->getString('status', 'active');
        $pager = new Paginator($repo->searchQuery($q, $status ?: null), max(1, $r->query->getInt('page', 1)), 50);
        $ids = array_map(fn (Employee $e) => $e->getId()->toRfc4122(), iterator_to_array($pager));
        $counts = [];
        if ($ids) {
            foreach ($this->em->createQuery('SELECT IDENTITY(a.employee) AS e, COUNT(a.id) AS n FROM App\Entity\Asset a WHERE a.employee IN (:ids) GROUP BY a.employee')
                ->setParameter('ids', $ids)->getArrayResult() as $row) {
                $counts[(string) $row['e']] = (int) $row['n'];
            }
        }

        return $this->render('employee/index.html.twig', ['pager' => $pager, 'q' => $q, 'status' => $status, 'counts' => $counts]);
    }

    #[Route('/new', name: 'employee_new', methods: ['GET', 'POST'])]
    public function new(Request $r): Response
    {
        return $this->form(new Employee(), $r, true);
    }

    #[Route('/{id}/edit', name: 'employee_edit', methods: ['GET', 'POST'])]
    public function edit(Employee $e, Request $r): Response
    {
        return $this->form($e, $r, false);
    }

    private function form(Employee $e, Request $r, bool $isNew): Response
    {
        $before = $this->snap($e);
        $form = $this->createForm(EmployeeFormType::class, $e);
        $form->handleRequest($r);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($newDep = trim((string) $form->get('newDepartment')->getData())) {
                $dep = $this->em->getRepository(Department::class)->findOneBy(['name' => $newDep]) ?? (new Department())->setName($newDep);
                $this->em->persist($dep);
                $e->setDepartment($dep);
            }
            $this->em->persist($e);
            [$old, $new] = AuditLogger::diff($isNew ? [] : $before, $this->snap($e));
            $this->audit->log($isNew ? 'employee.create' : 'employee.update', ['objectType' => 'employee', 'objectId' => $e->getId()->toRfc4122(), 'objectLabel' => $e->getName(), 'old' => $old ?: null, 'new' => $new]);
            $this->em->flush();
            $this->addFlash('success', 'Сохранено');

            return $this->redirectToRoute('employee_show', ['id' => $e->getId()]);
        }

        return $this->render('employee/form.html.twig', ['form' => $form, 'employee' => $e, 'is_new' => $isNew]);
    }

    private function snap(Employee $e): array
    {
        return ['displayName' => $e->getDisplayName(), 'fullNameRu' => $e->getFullNameRu(), 'fullNameEn' => $e->getFullNameEn(), 'email' => $e->getEmail(),
            'upn' => $e->getUpn(), 'position' => $e->getPosition(), 'department' => $e->getDepartment()?->getName(), 'company' => $e->getCompany(),
            'office' => $e->getOffice()?->getPathString(), 'manager' => $e->getManager()?->getName(), 'status' => $e->getStatus(),
            'external1cId' => $e->getExternal1cId(), 'entraObjectId' => $e->getEntraObjectId(), 'extensionAttribute' => $e->getExtensionAttribute()];
    }

    #[Route('/{id}', name: 'employee_show', requirements: ['id' => '[0-9a-f-]{36}'])]
    public function show(Employee $e, AssetRepository $assets): Response
    {
        return $this->render('employee/show.html.twig', [
            'employee' => $e,
            'assets' => $assets->findAssignedTo($e),
            'history' => $this->em->getRepository(AssetAssignment::class)->findBy(['employee' => $e], ['assignedAt' => 'DESC'], 100),
            'documents' => $this->em->getRepository(HandoverDocument::class)->findBy(['employee' => $e], ['createdAt' => 'DESC']),
            'licenses' => $this->em->getRepository(LicenseAssignment::class)->findBy(['employee' => $e, 'revokedAt' => null]),
            'notifications' => $this->em->getRepository(Notification::class)->findBy(['employee' => $e], ['createdAt' => 'DESC'], 20),
        ]);
    }

    /** Повторная отправка сотруднику полного перечня закрепленного оборудования (п. 2.1). */
    #[Route('/{id}/notify', name: 'employee_notify', methods: ['POST'])]
    public function notify(Employee $e, Request $r, NotificationService $n): Response
    {
        if (!$this->isCsrfTokenValid('notify' . $e->getId(), $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $notification = $n->notifyHoldings($e, 'Перечень закрепленного оборудования', null);
        $this->audit->log('notification.holdings', ['objectType' => 'employee', 'objectId' => $e->getId()->toRfc4122(), 'objectLabel' => $e->getName()], true);
        $this->addFlash($notification->getRecipient() ? 'success' : 'warning', $notification->getRecipient() ? 'Уведомление поставлено в очередь' : 'У сотрудника не указан email');

        return $this->redirectToRoute('employee_show', ['id' => $e->getId()]);
    }
}
