<?php

namespace App\Controller;

use App\Entity\Employee;
use App\Entity\License;
use App\Entity\LicenseAssignment;
use App\Entity\SecretValue;
use App\Entity\StoredFile;
use App\Entity\User;
use App\Exception\BusinessException;
use App\Form\LicenseFormType;
use App\Repository\AssetRepository;
use App\Service\AuditLogger;
use App\Service\FileStorage;
use App\Service\SecretVault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Учет лицензий (п. 16 ТЗ).
 */
#[Route('/licenses')]
#[IsGranted('ROLE_IT')]
class LicenseController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly AuditLogger $audit)
    {
    }

    private function snap(License $l): array
    {
        return ['vendor' => $l->getVendor(), 'product' => $l->getProduct(), 'type' => $l->getLicenseType(), 'contract' => $l->getContract(),
            'subscription' => $l->getSubscriptionId(), 'seats' => $l->getSeatsTotal(), 'validUntil' => $l->getValidUntil()?->format('Y-m-d'),
            'cost' => $l->getCost(), 'currency' => $l->getCurrency(), 'supplier' => $l->getSupplier(), 'responsible' => $l->getResponsible()?->getName(), 'status' => $l->getStatus()];
    }

    #[Route('', name: 'license_index')]
    public function index(Request $r): Response
    {
        $qb = $this->em->getRepository(License::class)->createQueryBuilder('l')->orderBy('l.vendor')->addOrderBy('l.product');
        if ($q = trim($r->query->getString('q'))) {
            $qb->where('LOWER(l.vendor) LIKE :q OR LOWER(l.product) LIKE :q OR LOWER(l.contract) LIKE :q OR LOWER(l.subscriptionId) LIKE :q')->setParameter('q', '%' . mb_strtolower($q) . '%');
        }

        return $this->render('license/index.html.twig', ['licenses' => $qb->getQuery()->getResult(), 'q' => $q]);
    }

    #[Route('/new', name: 'license_new', methods: ['GET', 'POST'])]
    public function new(Request $r): Response
    {
        return $this->form(new License(), $r, true);
    }

    #[Route('/{id}/edit', name: 'license_edit', methods: ['GET', 'POST'])]
    public function edit(License $l, Request $r): Response
    {
        return $this->form($l, $r, false);
    }

    private function form(License $l, Request $r, bool $isNew): Response
    {
        $before = $this->snap($l);
        $form = $this->createForm(LicenseFormType::class, $l);
        $form->handleRequest($r);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($l);
            $this->em->flush();
            [$old, $new] = AuditLogger::diff($isNew ? [] : $before, $this->snap($l));
            $this->audit->log($isNew ? 'license.create' : 'license.update', ['objectType' => 'license', 'objectId' => (string) $l->getId(), 'objectLabel' => (string) $l, 'old' => $old ?: null, 'new' => $new], true);
            $this->addFlash('success', 'Сохранено');

            return $this->redirectToRoute('license_show', ['id' => $l->getId()]);
        }

        return $this->render('license/form.html.twig', ['form' => $form, 'license' => $l, 'is_new' => $isNew]);
    }

    #[Route('/{id}', name: 'license_show', requirements: ['id' => '\d+'])]
    public function show(License $l, SecretVault $vault): Response
    {
        return $this->render('license/show.html.twig', [
            'license' => $l,
            'documents' => $this->em->getRepository(StoredFile::class)->findBy(['license' => $l], ['uploadedAt' => 'DESC']),
            'employees' => $this->em->getRepository(Employee::class)->findBy(['status' => 'active'], ['displayName' => 'ASC']),
            'vault_enabled' => $vault->isEnabled(),
            'doc_categories' => StoredFile::CATEGORIES,
        ]);
    }

    #[Route('/{id}/assign', name: 'license_assign', methods: ['POST'])]
    public function assign(License $l, Request $r, AssetRepository $assets): Response
    {
        $this->csrf('lic' . $l->getId(), $r);
        if ($l->getSeatsFree() <= 0) {
            throw new BusinessException('Нет свободных мест по лицензии');
        }
        $emp = $r->request->getString('employee') ? $this->em->find(Employee::class, $r->request->getString('employee')) : null;
        $asset = $r->request->getString('asset_code') ? $assets->findByCode($r->request->getString('asset_code')) : null;
        if ($r->request->getString('asset_code') && !$asset) {
            throw new BusinessException('Оборудование не найдено');
        }
        $a = new LicenseAssignment($l, $emp, $asset);
        $l->getAssignments()->add($a);
        $this->em->persist($a);
        $this->audit->log('license.assign', ['objectType' => 'license', 'objectId' => (string) $l->getId(), 'objectLabel' => (string) $l, 'new' => ['employee' => $emp?->getName(), 'asset' => $asset?->getInventoryNumber()]]);
        $this->em->flush();

        return $this->redirectToRoute('license_show', ['id' => $l->getId()]);
    }

    #[Route('/assignments/{id}/revoke', name: 'license_revoke', methods: ['POST'])]
    public function revoke(LicenseAssignment $a, Request $r): Response
    {
        $this->csrf('licrev' . $a->getId(), $r);
        $a->revoke();
        $this->audit->log('license.revoke', ['objectType' => 'license', 'objectId' => (string) $a->getLicense()->getId(), 'objectLabel' => (string) $a->getLicense(),
            'old' => ['employee' => $a->getEmployee()?->getName(), 'asset' => $a->getAsset()?->getInventoryNumber()]]);
        $this->em->flush();

        return $this->redirectToRoute('license_show', ['id' => $a->getLicense()->getId()]);
    }

    #[Route('/{id}/documents', name: 'license_document_upload', methods: ['POST'])]
    public function upload(License $l, Request $r, FileStorage $storage): Response
    {
        $this->csrf('lic' . $l->getId(), $r);
        $file = $r->files->get('file') ?? throw new BusinessException('Выберите файл');
        $s = $storage->storeUpload($file, 'attachments', FileStorage::ATTACHMENT_TYPES);
        $cat = in_array($r->request->getString('category'), StoredFile::CATEGORIES, true) ? $r->request->getString('category') : 'other';
        $doc = (new StoredFile($s['name'], $s['path'], $s['mime'], $s['size'], $s['sha256']))->setLicense($l)->setCategory($cat)
            ->setUploadedBy($this->getUser() instanceof User ? $this->getUser() : null);
        $this->em->persist($doc);
        $this->audit->log('document.upload', ['objectType' => 'license', 'objectId' => (string) $l->getId(), 'objectLabel' => (string) $l, 'new' => ['file' => $s['name']]]);
        $this->em->flush();

        return $this->redirectToRoute('license_show', ['id' => $l->getId()]);
    }

    // ---------------------------------------------- секретный ключ: шифруется, маскируется, не экспортируется

    #[Route('/{id}/secret', name: 'license_secret_reveal', methods: ['POST'])]
    #[IsGranted('ROLE_SECRET_VIEWER')]
    public function reveal(License $l, Request $r, SecretVault $vault, UserPasswordHasherInterface $hasher): Response
    {
        $this->csrf('licsecret' . $l->getId(), $r);
        /** @var User $u */
        $u = $this->getUser();
        if (!$hasher->isPasswordValid($u, $r->request->getString('password'))) {
            $this->audit->log('secret.view_denied', ['objectType' => 'license', 'objectId' => (string) $l->getId(), 'objectLabel' => (string) $l], true);
            throw new BusinessException('Неверный пароль подтверждения');
        }
        $value = $vault->reveal(SecretValue::OWNER_LICENSE_KEY, (string) $l->getId());
        $this->audit->log('secret.view', ['objectType' => 'license', 'objectId' => (string) $l->getId(), 'objectLabel' => (string) $l, 'comment' => 'Просмотр лицензионного ключа: ' . trim($r->request->getString('reason'))], true);
        $resp = $this->render('license/secret.html.twig', ['license' => $l, 'value' => $value]);
        $resp->headers->set('Cache-Control', 'no-store');

        return $resp;
    }

    #[Route('/{id}/secret/set', name: 'license_secret_set', methods: ['POST'])]
    #[IsGranted('ROLE_SECRET_VIEWER')]
    public function setSecret(License $l, Request $r, SecretVault $vault): Response
    {
        $this->csrf('licsecret' . $l->getId(), $r);
        $value = trim($r->request->getString('value'));
        if ($value === '') {
            $vault->remove(SecretValue::OWNER_LICENSE_KEY, (string) $l->getId());
            $l->setHasSecretKey(false);
        } else {
            $vault->store(SecretValue::OWNER_LICENSE_KEY, (string) $l->getId(), $value, $this->getUser()?->getUserIdentifier());
            $l->setHasSecretKey(true);
        }
        $this->audit->log($value === '' ? 'secret.delete' : 'secret.set', ['objectType' => 'license', 'objectId' => (string) $l->getId(), 'objectLabel' => (string) $l]);
        $this->em->flush();
        $this->addFlash('success', 'Сохранено');

        return $this->redirectToRoute('license_show', ['id' => $l->getId()]);
    }

    private function csrf(string $id, Request $r): void
    {
        if (!$this->isCsrfTokenValid($id, $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
