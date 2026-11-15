<?php

namespace App\Controller;

use App\Entity\ImportJob;
use App\Entity\ImportRow;
use App\Entity\User;
use App\Entity\ValueMapping;
use App\Enum\AssetStatus;
use App\Exception\BusinessException;
use App\Repository\EmployeeRepository;
use App\Repository\LocationRepository;
use App\Service\Import\ExcelImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Мастер импорта Excel (п. 8 ТЗ).
 */
#[Route('/import')]
#[IsGranted('ROLE_IT')]
class ImportController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly ExcelImportService $import)
    {
    }

    private function csrf(string $id, Request $r): void
    {
        if (!$this->isCsrfTokenValid($id, $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    #[Route('', name: 'import_index', methods: ['GET', 'POST'])]
    public function index(Request $r): Response
    {
        if ($r->isMethod('POST')) {
            $this->csrf('import_upload', $r);
            $file = $r->files->get('file') ?? throw new BusinessException('Выберите файл Excel');
            /** @var User $u */
            $u = $this->getUser();
            $job = $this->import->upload($file, $u);

            return $this->redirectToRoute('import_mapping', ['id' => $job->getId()]);
        }

        return $this->render('import/index.html.twig', [
            'jobs' => $this->em->getRepository(ImportJob::class)->findBy([], ['id' => 'DESC'], 30),
            'fields' => ExcelImportService::FIELDS,
        ]);
    }

    /** Шаги 2–3: структура и сопоставление колонок. */
    #[Route('/{id}/mapping', name: 'import_mapping', methods: ['GET', 'POST'])]
    public function mapping(ImportJob $job, Request $r): Response
    {
        if (!$job->isEditable()) {
            return $this->redirectToRoute('import_report', ['id' => $job->getId()]);
        }
        if ($r->isMethod('POST')) {
            $this->csrf('import_map' . $job->getId(), $r);
            if ($r->request->has('change_sheet')) {
                $this->import->changeSheet($job, $r->request->getString('sheet'), (int) $r->request->getString('header_row') ?: null);
                $this->em->flush();

                return $this->redirectToRoute('import_mapping', ['id' => $job->getId()]);
            }
            $this->import->analyze($job, $r->request->all('map'));

            return $this->redirectToRoute('import_report', ['id' => $job->getId()]);
        }

        return $this->render('import/mapping.html.twig', [
            'job' => $job,
            'fields' => ExcelImportService::FIELDS,
            'sheets' => $this->import->sheetNames($job),
            'missing' => $this->import->missingRequired($job->getColumnMap()),
        ]);
    }

    /** Шаги 5–6: предварительный отчет, исправление/исключение/подтверждение строк. */
    #[Route('/{id}/report', name: 'import_report', methods: ['GET'])]
    public function report(ImportJob $job, Request $r, LocationRepository $locations): Response
    {
        $filter = $r->query->getString('filter', 'all');
        $rows = array_values(array_filter($job->getRows()->toArray(), fn (ImportRow $row) => match ($filter) {
            'errors' => $row->hasErrors(),
            'warnings' => $row->hasWarnings(),
            'create', 'update', 'skip' => $row->getAction() === $filter,
            default => true,
        }));
        $page = max(1, $r->query->getInt('page', 1));
        $perPage = 100;

        return $this->render('import/report.html.twig', [
            'job' => $job,
            's' => $job->getSummary(),
            'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total_rows' => count($rows),
            'page' => $page,
            'pages' => max(1, (int) ceil(count($rows) / $perPage)),
            'filter' => $filter,
            'fields' => ExcelImportService::FIELDS,
            'statuses' => AssetStatus::cases(),
            'locations' => $locations->getFlatTree(true),
        ]);
    }

    #[Route('/rows/{id}/action', name: 'import_row_action', methods: ['POST'])]
    public function rowAction(ImportRow $row, Request $r): Response
    {
        $this->csrf('import_row' . $row->getJob()->getId(), $r);
        $this->import->setAction($row, $r->request->getString('action'));
        $this->em->flush();

        return $this->back($r, $row->getJob());
    }

    #[Route('/{id}/bulk', name: 'import_bulk', methods: ['POST'])]
    public function bulk(ImportJob $job, Request $r): Response
    {
        $this->csrf('import_row' . $job->getId(), $r);
        $what = $r->request->getString('what');
        foreach ($job->getRows() as $row) {
            if ($what === 'skip_warnings' && $row->hasWarnings()) {
                $this->import->setAction($row, ImportRow::ACTION_SKIP);
            } elseif ($what === 'reset') {
                $raw = $row->getRaw();
                unset($raw['_excluded']);
                $row->setRaw($raw);
                $row->setAction(ImportRow::ACTION_SKIP);
            }
        }
        if ($what === 'reset') {
            $this->import->analyzeRows($job);
        }
        $this->em->flush();

        return $this->back($r, $job);
    }

    #[Route('/rows/{id}/edit', name: 'import_row_edit', methods: ['POST'])]
    public function rowEdit(ImportRow $row, Request $r): Response
    {
        $this->csrf('import_row' . $row->getJob()->getId(), $r);
        $this->import->editRow($row, $r->request->all('v'));
        $this->em->flush();
        $this->addFlash('success', sprintf('Строка %d исправлена и проверена повторно', $row->getRowNumber()));

        return $this->back($r, $row->getJob());
    }

    /** Добавление значения в справочник соответствий (статус/расположение/сотрудник). */
    #[Route('/{id}/mapping-value', name: 'import_map_value', methods: ['POST'])]
    public function mapValue(ImportJob $job, Request $r, EmployeeRepository $employees): Response
    {
        $this->csrf('import_row' . $job->getId(), $r);
        $kind = $r->request->getString('kind');
        $target = $r->request->getString('target');
        if (!in_array($kind, [ValueMapping::KIND_STATUS, ValueMapping::KIND_LOCATION, ValueMapping::KIND_EMPLOYEE], true) || $target === '') {
            throw new BusinessException('Выберите значение справочника');
        }
        if ($kind === ValueMapping::KIND_EMPLOYEE && !$employees->find($target)) {
            throw new BusinessException('Сотрудник не найден');
        }
        $this->import->addMapping($job, $kind, $r->request->getString('source'), $target);
        $this->em->flush();
        $this->addFlash('success', 'Соответствие сохранено в справочник, строки проверены повторно');

        return $this->back($r, $job);
    }

    /** Шаг 7: запись только после явного подтверждения. */
    #[Route('/{id}/commit', name: 'import_commit', methods: ['POST'])]
    public function commit(ImportJob $job, Request $r): Response
    {
        $this->csrf('import_commit' . $job->getId(), $r);
        if (!$r->request->getBoolean('confirm')) {
            throw new BusinessException('Подтвердите запись отметкой «Я проверил(а) отчет»');
        }
        /** @var User $u */
        $u = $this->getUser();
        $p = $this->import->commit($job, $u, $r->request->getBoolean('create_employees'));
        $this->addFlash('success', sprintf('Импорт записан: создано %d, обновлено %d, пропущено %d', $p['created'], $p['updated'], $p['skipped']));

        return $this->redirectToRoute('import_protocol', ['id' => $job->getId()]);
    }

    #[Route('/{id}/cancel', name: 'import_cancel', methods: ['POST'])]
    public function cancel(ImportJob $job, Request $r): Response
    {
        $this->csrf('import_commit' . $job->getId(), $r);
        $this->import->cancel($job);

        return $this->redirectToRoute('import_index');
    }

    /** Шаг 8: итоговый протокол. */
    #[Route('/{id}/protocol', name: 'import_protocol')]
    public function protocol(ImportJob $job): Response
    {
        return $this->render('import/protocol.html.twig', ['job' => $job]);
    }

    private function back(Request $r, ImportJob $job): Response
    {
        $ref = $r->headers->get('referer');

        return $ref ? $this->redirect($ref) : $this->redirectToRoute('import_report', ['id' => $job->getId()]);
    }
}
