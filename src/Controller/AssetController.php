<?php

namespace App\Controller;

use App\Entity\Asset;
use App\Entity\AssetType;
use App\Entity\LicenseAssignment;
use App\Entity\Location;
use App\Entity\SecretValue;
use App\Entity\StoredFile;
use App\Entity\User;
use App\Enum\AssetStatus;
use App\Exception\BusinessException;
use App\Form\AssetFormType;
use App\Repository\AssetRepository;
use App\Repository\AuditLogRepository;
use App\Repository\LocationRepository;
use App\Service\AssetService;
use App\Service\AuditLogger;
use App\Service\FileStorage;
use App\Service\LabelService;
use App\Service\SecretVault;
use App\Util\HeaderUtils;
use App\Util\Paginator;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/assets')]
#[IsGranted('ROLE_USER')]
class AssetController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssetService $assetService,
        private readonly AuditLogger $audit,
    ) {
    }

    private function filters(Request $r): array
    {
        return array_filter([
            'q' => trim($r->query->getString('q')),
            'status' => $r->query->getString('status'),
            'type' => $r->query->getString('type'),
            'category' => $r->query->getString('category'),
            'location' => $r->query->getString('location'),
            'employee' => $r->query->getString('employee'),
            'archived' => $r->query->getBoolean('archived') ? '1' : '',
            'date' => $r->query->getString('date'),
            'full' => trim($r->query->getString('full')),
            'inv' => trim($r->query->getString('inv')),
            'name' => trim($r->query->getString('name')),
            'emp_en' => trim($r->query->getString('emp_en')),
            'emp_ru' => trim($r->query->getString('emp_ru')),
            'comment' => trim($r->query->getString('comment')),
            'rk' => $r->query->getString('rk'),
            'sort' => $r->query->getString('sort'),
            'dir' => $r->query->getString('dir'),
        ], fn ($v) => $v !== '');
    }

    #[Route('', name: 'asset_index')]
    public function index(Request $request, AssetRepository $repo, LocationRepository $locations): Response
    {
        $f = $this->filters($request);
        $pager = new Paginator($repo->registryQuery($f), max(1, $request->query->getInt('page', 1)), 50);

        return $this->render('asset/index.html.twig', [
            'pager' => $pager,
            'f' => $f,
            'types' => $this->em->getRepository(AssetType::class)->findBy([], ['category' => 'ASC', 'name' => 'ASC']),
            'locations' => $locations->getFlatTree(true),
        ]);
    }

    /** Экспорт реестра в XLSX. Секреты и recovery keys в экспорт не попадают (п. 9, 16). */
    #[Route('/export', name: 'asset_export')]
    #[IsGranted('ROLE_IT')]
    public function export(Request $request, AssetRepository $repo): Response
    {
        $f = $this->filters($request);
        $rows = $repo->registryQuery($f)->getQuery()->toIterable();
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Реестр');
        $sheet->fromArray(['Инв. номер', 'Полный инв. номер', 'Штрихкод', 'Категория', 'Тип', 'Наименование', 'Производитель', 'Модель', 'Серийный №',
            'Статус', 'Сотрудник', 'Город', 'Офис', 'Этаж', 'Помещение', 'Комплект', 'Состояние', 'Recovery key (наличие)', 'ID 1С', 'Примечание', 'Изменено'], null, 'A1');
        $i = 2;
        $count = 0;
        foreach ($rows as $a) {
            /** @var Asset $a */
            $sheet->fromArray([
                $a->getInventoryNumber(), $a->getFullInventoryNumber(), $a->getBarcode(), $a->getType()?->getCategory(), $a->getType()?->getName(),
                $a->getName(), $a->getManufacturer(), $a->getModel(), $a->getSerialNumber(), $a->getStatus()->label(),
                $a->getEmployee()?->getName(), $a->getCity()?->getName(), $a->getOffice()?->getName(), $a->getFloor()?->getName(), $a->getRoom()?->getName(),
                $a->getBundle()?->getName(), $a->getConditionLabel(), $a->hasRecoveryKey() ? 'Да' : 'Нет', $a->getExternal1cId(), $a->getComment(),
                $a->getUpdatedAt()->format('d.m.Y H:i'),
            ], null, 'A' . $i++, true);
            ++$count;
        }
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        $sheet->freezePane('A2');
        foreach (range('A', 'U') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $this->audit->log('asset.export', ['objectType' => 'asset', 'comment' => sprintf('Экспорт реестра: %d записей, фильтр %s', $count, json_encode($f, JSON_UNESCAPED_UNICODE))], true);

        $resp = new StreamedResponse(fn () => (new Xlsx($book))->save('php://output'));
        $resp->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $resp->headers->set('Content-Disposition', HeaderUtils::disposition('reestr_' . date('Ymd_His') . '.xlsx'));

        return $resp;
    }

    #[Route('/new', name: 'asset_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_IT')]
    public function new(Request $request): Response
    {
        $asset = new Asset();
        if ($code = $request->query->getString('inv')) {
            $asset->setInventoryNumber($code);
        }
        $form = $this->createForm(AssetFormType::class, $asset, ['is_new' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $asset->setStatus(AssetStatus::from($form->get('initialStatus')->getData()));
            $this->applyAttributes($asset, $request);
            $this->assetService->create($asset);
            $this->em->flush();
            $this->addFlash('success', sprintf('Актив %s создан', $asset->getInventoryNumber()));

            return $this->redirectToRoute('asset_show', ['id' => $asset->getId()]);
        }

        return $this->render('asset/form.html.twig', ['form' => $form, 'asset' => $asset, 'is_new' => true, 'types' => $this->typesWithAttributes(), 'current_attrs' => $this->currentAttrs($asset)]);
    }

    #[Route('/{id}', name: 'asset_show', requirements: ['id' => '[0-9a-f-]{36}'])]
    public function show(Asset $asset, AuditLogRepository $auditRepo, SecretVault $vault, LocationRepository $locations): Response
    {
        return $this->render('asset/show.html.twig', [
            'asset' => $asset,
            'history' => $this->assetService->history($asset),
            'documents' => $this->em->getRepository(StoredFile::class)->findBy(['asset' => $asset], ['uploadedAt' => 'DESC']),
            'licenses' => $this->em->getRepository(LicenseAssignment::class)->findBy(['asset' => $asset, 'revokedAt' => null]),
            'audit' => $this->isGranted('ROLE_IT') ? $auditRepo->forObject('asset', $asset->getId()->toRfc4122(), 30) : [],
            'transitions' => array_merge($asset->getStatus()->allowedTransitions(), $this->isGranted('ROLE_ADMIN') ? $asset->getStatus()->adminRestoreTransitions() : []),
            'locations' => $locations->getFlatTree(true),
            'vault_enabled' => $vault->isEnabled(),
            'has_secret' => $vault->isEnabled() && $this->em->getRepository(SecretValue::class)->count(['ownerType' => SecretValue::OWNER_RECOVERY_KEY, 'ownerId' => $asset->getId()->toRfc4122()]) > 0,
            'doc_categories' => StoredFile::CATEGORIES,
        ]);
    }

    #[Route('/{id}/edit', name: 'asset_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_IT')]
    public function edit(Asset $asset, Request $request): Response
    {
        $before = AssetService::snapshot($asset);
        $form = $this->createForm(AssetFormType::class, $asset);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyAttributes($asset, $request);
            $this->assetService->saveEdit($asset, $before);
            $this->em->flush();
            $this->addFlash('success', 'Изменения сохранены');

            return $this->redirectToRoute('asset_show', ['id' => $asset->getId()]);
        }

        return $this->render('asset/form.html.twig', ['form' => $form, 'asset' => $asset, 'is_new' => false, 'types' => $this->typesWithAttributes(), 'current_attrs' => $this->currentAttrs($asset)]);
    }

    /** Значения настраиваемых атрибутов (п. 6.2) приходят как attrs[defId]. */
    private function applyAttributes(Asset $asset, Request $request): void
    {
        $values = $request->request->all('attrs');
        $type = $asset->getType();
        $errors = [];
        foreach ($type?->getAttributes() ?? [] as $def) {
            try {
                $v = $def->normalize($values[$def->getId()] ?? null);
                if ($v === null && $def->isRequired()) {
                    $errors[] = sprintf('Заполните «%s»', $def->getLabel());
                    continue;
                }
                $asset->setAttribute($def, $v);
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        // атрибуты другого типа (после смены типа) удаляются
        foreach ($asset->getAttributeValues()->toArray() as $val) {
            if ($val->getDefinition()->getAssetType() !== $type) {
                $asset->getAttributeValues()->removeElement($val);
            }
        }
        if ($errors) {
            throw new BusinessException(implode('; ', $errors));
        }
    }

    private function currentAttrs(Asset $asset): array
    {
        $out = [];
        foreach ($asset->getAttributeValues() as $v) {
            $out[$v->getDefinition()->getId()] = $v->getValue();
        }

        return $out;
    }

    private function typesWithAttributes(): array
    {
        $out = [];
        foreach ($this->em->getRepository(AssetType::class)->findAll() as $t) {
            $out[$t->getId()] = array_map(fn ($d) => [
                'id' => $d->getId(), 'label' => $d->getLabel(), 'type' => $d->getDataType(), 'unit' => $d->getUnit(),
                'options' => $d->getOptions(), 'required' => $d->isRequired(),
            ], $t->getAttributes()->toArray());
        }

        return $out;
    }

    #[Route('/{id}/status', name: 'asset_status', methods: ['POST'])]
    #[IsGranted('ROLE_IT')]
    public function status(Asset $asset, Request $request): Response
    {
        $this->checkCsrf('asset_op' . $asset->getId(), $request);
        $to = AssetStatus::tryFrom($request->request->getString('status')) ?? throw new BusinessException('Неизвестный статус');
        $comment = trim($request->request->getString('comment')) ?: null;
        if ($to === AssetStatus::Assigned) {
            throw new BusinessException('Закрепление за сотрудником выполняется через «Выдачу» с оформлением акта');
        }
        if ($asset->getStatus()->isTerminal() || in_array($to, [AssetStatus::WrittenOff, AssetStatus::Archived], true)) {
            if (!$comment) {
                throw new BusinessException('Для списания/архивации/восстановления укажите основание в комментарии');
            }
        }
        $this->assetService->changeStatus($asset, $to, $comment);
        $this->em->flush();
        $this->addFlash('success', sprintf('Статус изменен: %s', $to->label()));

        return $this->redirectToRoute('asset_show', ['id' => $asset->getId()]);
    }

    #[Route('/{id}/move', name: 'asset_move', methods: ['POST'])]
    #[IsGranted('ROLE_IT')]
    public function move(Asset $asset, Request $request, LocationRepository $locations): Response
    {
        $this->checkCsrf('asset_op' . $asset->getId(), $request);
        $loc = (int) $request->request->getString('location') ? $locations->find((int) $request->request->getString('location')) : null;
        $this->assetService->move($asset, $loc, trim($request->request->getString('comment')) ?: null, 'web', $request->request->getBoolean('installed'));
        $this->em->flush();
        $this->addFlash('success', 'Местоположение изменено');

        return $this->redirectToRoute('asset_show', ['id' => $asset->getId()]);
    }

    // ------------------------------------------------------------ вложения

    #[Route('/{id}/documents', name: 'asset_document_upload', methods: ['POST'])]
    #[IsGranted('ROLE_IT')]
    public function uploadDocument(Asset $asset, Request $request, FileStorage $storage): Response
    {
        $this->checkCsrf('asset_op' . $asset->getId(), $request);
        $file = $request->files->get('file') ?? throw new BusinessException('Выберите файл');
        $category = $request->request->getString('category', 'other');
        if (!in_array($category, StoredFile::CATEGORIES, true)) {
            $category = 'other';
        }
        $s = $storage->storeUpload($file, 'attachments', FileStorage::ATTACHMENT_TYPES);
        $doc = (new StoredFile($s['name'], $s['path'], $s['mime'], $s['size'], $s['sha256']))
            ->setAsset($asset)->setCategory($category)->setUploadedBy($this->getUser() instanceof User ? $this->getUser() : null);
        $this->em->persist($doc);
        $this->audit->log('document.upload', ['objectType' => 'asset', 'objectId' => $asset->getId()->toRfc4122(), 'objectLabel' => (string) $asset,
            'new' => ['file' => $s['name'], 'category' => $category, 'sha256' => $s['sha256']]]);
        $this->em->flush();
        $this->addFlash('success', 'Файл загружен');

        return $this->redirectToRoute('asset_show', ['id' => $asset->getId(), '_fragment' => 'documents']);
    }

    #[Route('/documents/{id}', name: 'document_download', methods: ['GET'])]
    public function download(StoredFile $doc, FileStorage $storage): Response
    {
        $resp = new Response($storage->read($doc->getStoragePath()));
        $resp->headers->set('Content-Type', $doc->getMimeType());
        $inline = in_array($doc->getMimeType(), ['application/pdf', 'image/jpeg', 'image/png'], true);
        $resp->headers->set('Content-Disposition', HeaderUtils::disposition($doc->getOriginalName(), $inline));
        $resp->headers->set('X-Content-Type-Options', 'nosniff');
        $resp->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");

        return $resp;
    }

    #[Route('/documents/{id}/delete', name: 'document_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteDocument(StoredFile $doc, Request $request, FileStorage $storage): Response
    {
        $this->checkCsrf('doc_delete' . $doc->getId(), $request);
        $asset = $doc->getAsset();
        $this->audit->log('document.delete', ['objectType' => $asset ? 'asset' : 'license', 'objectId' => $asset?->getId()->toRfc4122() ?? (string) $doc->getLicense()?->getId(),
            'old' => ['file' => $doc->getOriginalName(), 'sha256' => $doc->getSha256()]]);
        $storage->delete($doc->getStoragePath());
        $this->em->remove($doc);
        $this->em->flush();

        return $asset ? $this->redirectToRoute('asset_show', ['id' => $asset->getId()]) : $this->redirectToRoute('license_show', ['id' => $doc->getLicense()?->getId()]);
    }

    // ------------------------------------------------------------ этикетки

    #[Route('/{id}/label', name: 'asset_label')]
    #[IsGranted('ROLE_IT')]
    public function label(Asset $asset, Request $request, LabelService $labels): Response
    {
        $pdf = $labels->labelsPdf([$asset], $request->query->getString('format', '62x29'), max(1, $request->query->getInt('copies', 1)));
        $this->audit->log('label.print', ['objectType' => 'asset', 'objectId' => $asset->getId()->toRfc4122(), 'objectLabel' => (string) $asset], true);

        return new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => HeaderUtils::disposition('label_' . $asset->getInventoryNumber() . '.pdf', true)]);
    }

    #[Route('/labels', name: 'asset_labels_batch', methods: ['POST'])]
    #[IsGranted('ROLE_IT')]
    public function labelsBatch(Request $request, AssetRepository $repo, LabelService $labels): Response
    {
        $this->checkCsrf('labels_batch', $request);
        $ids = array_filter($request->request->all('ids'));
        $codes = preg_split('/[\s,;]+/', $request->request->getString('codes'), -1, PREG_SPLIT_NO_EMPTY);
        $assets = $ids ? $repo->findBy(['id' => $ids], ['inventoryNumber' => 'ASC']) : [];
        foreach ($codes as $c) {
            if ($a = $repo->findByCode($c)) {
                $assets[] = $a;
            }
        }
        if (!$assets) {
            throw new BusinessException('Не выбрано ни одного актива');
        }
        $pdf = $labels->labelsPdf($assets, $request->request->getString('format', '62x29'), 1);
        $this->audit->log('label.print', ['objectType' => 'asset', 'comment' => sprintf('Пакетная печать: %d этикеток', count($assets))], true);

        return new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => HeaderUtils::disposition('labels_' . date('Ymd_His') . '.pdf', true)]);
    }

    #[Route('/barcode/{code}.svg', name: 'asset_barcode_svg', requirements: ['code' => '[\x20-\x7E]{1,64}'])]
    public function barcodeSvg(string $code, LabelService $labels): Response
    {
        return new Response($labels->barcodeSvg(Asset::normalizeCode($code), 2, 50), 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=86400']);
    }

    // ------------------------------------------------------------ recovery key (п. 9)

    #[Route('/{id}/secret', name: 'asset_secret_reveal', methods: ['POST'])]
    #[IsGranted('ROLE_SECRET_VIEWER')]
    public function revealSecret(Asset $asset, Request $request, SecretVault $vault, UserPasswordHasherInterface $hasher): Response
    {
        $this->checkCsrf('asset_secret' . $asset->getId(), $request);
        /** @var User $user */
        $user = $this->getUser();
        // повторное подтверждение паролем
        if (!$hasher->isPasswordValid($user, $request->request->getString('password'))) {
            $this->audit->log('secret.view_denied', ['objectType' => 'asset', 'objectId' => $asset->getId()->toRfc4122(), 'objectLabel' => (string) $asset, 'comment' => 'Неверный пароль подтверждения'], true);
            throw new BusinessException('Неверный пароль подтверждения');
        }
        $value = $vault->reveal(SecretValue::OWNER_RECOVERY_KEY, $asset->getId()->toRfc4122());
        $this->audit->log('secret.view', ['objectType' => 'asset', 'objectId' => $asset->getId()->toRfc4122(), 'objectLabel' => (string) $asset,
            'comment' => 'Просмотр recovery key: ' . trim($request->request->getString('reason'))], true);

        $resp = $this->render('asset/secret.html.twig', ['asset' => $asset, 'value' => $value]);
        $resp->headers->set('Cache-Control', 'no-store');

        return $resp;
    }

    #[Route('/{id}/secret/set', name: 'asset_secret_set', methods: ['POST'])]
    #[IsGranted('ROLE_SECRET_VIEWER')]
    public function setSecret(Asset $asset, Request $request, SecretVault $vault): Response
    {
        $this->checkCsrf('asset_secret' . $asset->getId(), $request);
        $value = trim($request->request->getString('value'));
        if ($value === '') {
            $vault->remove(SecretValue::OWNER_RECOVERY_KEY, $asset->getId()->toRfc4122());
            $action = 'secret.delete';
        } else {
            $vault->store(SecretValue::OWNER_RECOVERY_KEY, $asset->getId()->toRfc4122(), $value, $this->getUser()?->getUserIdentifier());
            $asset->setHasRecoveryKey(true)->setRecoveryKeyStorage('Система учета (зашифровано)');
            $action = 'secret.set';
        }
        $this->audit->log($action, ['objectType' => 'asset', 'objectId' => $asset->getId()->toRfc4122(), 'objectLabel' => (string) $asset]);
        $this->em->flush();
        $this->addFlash('success', 'Сохранено');

        return $this->redirectToRoute('asset_show', ['id' => $asset->getId()]);
    }

    // ------------------------------------------------------------ безвозвратное удаление (только администратор)

    #[Route('/delete', name: 'asset_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, AssetRepository $repo, FileStorage $storage, SecretVault $vault, UserPasswordHasherInterface $hasher): Response
    {
        $this->checkCsrf('asset_delete', $request);
        /** @var User $user */
        $user = $this->getUser();
        $mode = $request->request->getString('mode');
        if (!$hasher->isPasswordValid($user, $request->request->getString('password'))) {
            $this->audit->log('asset.delete_denied', ['objectType' => 'asset', 'comment' => 'Неверный пароль подтверждения'], true);
            throw new BusinessException('Неверный пароль подтверждения');
        }

        if ($mode === 'all') {
            $f = $this->filters(Request::create('', 'GET', $request->request->all('f')));
            $ids = array_map(fn ($r) => (string) $r['id'], (clone $repo->registryQuery($f))->select('a.id')->resetDQLPart('orderBy')->getQuery()->getScalarResult());
        } else {
            $ids = array_values(array_filter($request->request->all('ids'), fn ($id) => is_string($id) && preg_match('/^[0-9a-f-]{36}$/', $id)));
        }
        if (!$ids) {
            throw new BusinessException('Не выбрано ни одного актива');
        }

        $deleted = 0;
        foreach (array_chunk($ids, 100) as $chunk) {
            foreach ($repo->findBy(['id' => $chunk]) as $asset) {
                $id = $asset->getId()->toRfc4122();
                foreach ($this->em->getRepository(StoredFile::class)->findBy(['asset' => $asset]) as $doc) {
                    $storage->delete($doc->getStoragePath());
                }
                $vault->remove(SecretValue::OWNER_RECOVERY_KEY, $id);
                $this->audit->log('asset.delete', ['objectType' => 'asset', 'objectId' => $id, 'objectLabel' => (string) $asset,
                    'old' => ['inventoryNumber' => $asset->getInventoryNumber(), 'name' => $asset->getName(), 'serialNumber' => $asset->getSerialNumber(), 'status' => $asset->getStatus()->value]]);
                $this->em->remove($asset);
                ++$deleted;
            }
            $this->em->flush();
            $this->em->clear();
        }
        $this->addFlash('success', sprintf('Удалено активов: %d', $deleted));

        return $this->redirectToRoute('asset_index');
    }

    private function checkCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен');
        }
    }
}
