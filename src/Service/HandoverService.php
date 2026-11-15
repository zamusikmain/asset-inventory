<?php

namespace App\Service;

use App\Entity\Asset;
use App\Entity\AssetAssignment;
use App\Entity\DigitalSignature;
use App\Entity\Employee;
use App\Entity\HandoverDocument;
use App\Entity\InventoryCampaign;
use App\Entity\Location;
use App\Entity\User;
use App\Enum\AssetStatus;
use App\Exception\BusinessException;
use App\Repository\AssetRepository;
use App\Service\Signature\SignatureVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Выдача и возврат с формированием PDF-акта, уведомлением и подписанием ЭЦП (п. 13 ТЗ).
 */
class HandoverService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssetService $assetService,
        private readonly AssetRepository $assets,
        private readonly PdfRenderer $pdf,
        private readonly FileStorage $storage,
        private readonly NotificationService $notifications,
        private readonly SignatureVerifier $verifier,
        private readonly AuditLogger $audit,
        private readonly string $orgName,
        #[Autowire('%env(int:SIGN_DEADLINE_DAYS)%')]
        private readonly int $signDeadlineDays,
    ) {
    }

    public static function itemSnapshot(Asset $a): array
    {
        $bundle = $a->getBundle();

        return [
            'id' => $a->getId()->toRfc4122(),
            'inventory_number' => $a->getInventoryNumber(),
            'full_inventory_number' => $a->getFullInventoryNumber(),
            'name' => $a->getName(),
            'manufacturer' => $a->getManufacturer(),
            'model' => $a->getModel(),
            'serial_number' => $a->getSerialNumber(),
            'bundle' => $bundle ? sprintf('%s (%d поз.)', $bundle->getName(), $bundle->getAssets()->count()) : null,
            'condition' => $a->getConditionLabel(),
            'location' => $a->getLocation()?->getPathString(),
        ];
    }

    private function nextNumber(string $type): string
    {
        $prefix = match ($type) {
            HandoverDocument::TYPE_ISSUE => 'АПП',
            HandoverDocument::TYPE_RETURN => 'АВ',
            default => 'АИ',
        };
        $year = date('Y');
        $conn = $this->em->getConnection();
        $conn->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:k))', ['k' => 'docnum:' . $prefix . $year]);
        $n = (int) $conn->fetchOne('SELECT COUNT(*) FROM handover_documents WHERE number LIKE :p', ['p' => "$prefix-$year-%"]);

        return sprintf('%s-%s-%05d', $prefix, $year, $n + 1);
    }

    private function header(?Employee $e, User $it): array
    {
        return [
            'org' => $this->orgName,
            'employee' => $e ? [
                'name' => $e->getName(),
                'name_en' => $e->getFullNameEn() ?: $e->getDisplayName(),
                'position' => $e->getPosition(),
                'department' => $e->getDepartment()?->getName(),
                'email' => $e->getEmail(),
                'office' => $e->getOffice()?->getPathString(),
            ] : null,
            'it' => ['name' => $it->getFullName(), 'username' => $it->getUsername(), 'email' => $it->getEmail()],
        ];
    }

    /**
     * Выдача (п. 13.1): проверка доступности, операция, PDF-акт, уведомление.
     *
     * @param list<Asset> $items
     */
    public function issue(Employee $employee, array $items, User $it, ?string $comment = null): HandoverDocument
    {
        if (!$items) {
            throw new BusinessException('Добавьте хотя бы одну позицию');
        }
        if ($employee->getStatus() === 'dismissed') {
            throw new BusinessException('Сотрудник уволен — выдача невозможна');
        }
        $errors = array_filter(array_map(fn (Asset $a) => $this->assetService->checkIssuable($a, $employee), $items));
        if ($errors) {
            throw new BusinessException(implode('; ', $errors));
        }

        return $this->em->wrapInTransaction(function () use ($employee, $items, $it, $comment) {
            $doc = new HandoverDocument($this->nextNumber(HandoverDocument::TYPE_ISSUE), HandoverDocument::TYPE_ISSUE);
            $doc->setEmployee($employee)->setItUser($it)
                ->setHeader($this->header($employee, $it) + ['comment' => $comment])
                ->setSignDeadline(new \DateTimeImmutable(sprintf('+%d days', $this->signDeadlineDays)));
            $this->em->persist($doc);

            foreach ($items as $a) {
                $this->assetService->assign($a, $employee, $doc, $comment);
            }
            $doc->setItems(array_map([self::class, 'itemSnapshot'], $items));
            $this->em->flush();

            $doc->setCurrentList(array_map([self::class, 'itemSnapshot'], $this->assets->findAssignedTo($employee)));
            $this->renderPdf($doc);
            $this->audit->log('handover.issue', [
                'objectType' => 'handover_document', 'objectId' => $doc->getId()->toRfc4122(), 'objectLabel' => $doc->getNumber(),
                'new' => ['employee' => $employee->getName(), 'items' => array_column($doc->getItems(), 'inventory_number')],
                'document' => $doc->getNumber(), 'comment' => $comment,
            ]);
            $this->em->flush();

            return $doc;
        });
    }

    /**
     * Возврат: позиции снимаются с сотрудника, получают статус и место хранения.
     *
     * @param list<Asset> $items
     */
    public function return(Employee $employee, array $items, User $it, ?Location $toLocation, AssetStatus $toStatus = AssetStatus::InStock, ?string $comment = null): HandoverDocument
    {
        if (!$items) {
            throw new BusinessException('Добавьте хотя бы одну позицию');
        }
        foreach ($items as $a) {
            if ($a->getEmployee() !== $employee) {
                throw new BusinessException(sprintf('%s не закреплен за %s', $a->getInventoryNumber(), $employee->getName()));
            }
        }

        return $this->em->wrapInTransaction(function () use ($employee, $items, $it, $toLocation, $toStatus, $comment) {
            $doc = new HandoverDocument($this->nextNumber(HandoverDocument::TYPE_RETURN), HandoverDocument::TYPE_RETURN);
            $doc->setEmployee($employee)->setItUser($it)
                ->setHeader($this->header($employee, $it) + ['comment' => $comment, 'return_status' => $toStatus->label(), 'return_location' => $toLocation?->getPathString()])
                ->setSignDeadline(new \DateTimeImmutable(sprintf('+%d days', $this->signDeadlineDays)));
            $doc->setItems(array_map([self::class, 'itemSnapshot'], $items));
            $this->em->persist($doc);

            foreach ($items as $a) {
                $this->assetService->returnAsset($a, $doc, $toLocation, $toStatus, $comment);
            }
            $this->em->flush();

            $doc->setCurrentList(array_map([self::class, 'itemSnapshot'], $this->assets->findAssignedTo($employee)));
            $this->renderPdf($doc);
            $this->audit->log('handover.return', [
                'objectType' => 'handover_document', 'objectId' => $doc->getId()->toRfc4122(), 'objectLabel' => $doc->getNumber(),
                'new' => ['employee' => $employee->getName(), 'items' => array_column($doc->getItems(), 'inventory_number')],
                'document' => $doc->getNumber(), 'comment' => $comment,
            ]);
            $this->em->flush();

            return $doc;
        });
    }

    /** Отправка уведомления выполняется после фиксации транзакции. */
    public function notify(HandoverDocument $doc): void
    {
        if (!$doc->getEmployee()) {
            return;
        }
        $isIssue = $doc->getType() === HandoverDocument::TYPE_ISSUE;
        $this->notifications->notifyHoldings(
            $doc->getEmployee(),
            $isIssue ? 'Выдача оборудования' : 'Возврат оборудования',
            $doc,
            $isIssue ? $doc->getItems() : [],
            $isIssue ? [] : $doc->getItems(),
        );
    }

    public function inventoryAct(InventoryCampaign $c, User $by, array $summary, array $sections): HandoverDocument
    {
        return $this->em->wrapInTransaction(function () use ($c, $by, $summary, $sections) {
            $doc = new HandoverDocument($this->nextNumber(HandoverDocument::TYPE_INVENTORY), HandoverDocument::TYPE_INVENTORY);
            $doc->setCampaign($c)->setItUser($by)->setStatus(HandoverDocument::STATUS_NOT_REQUIRED)
                ->setHeader($this->header(null, $by) + ['campaign' => (string) $c, 'summary' => $summary]);
            $this->em->persist($doc);
            $pdf = $this->pdf->render('pdf/inventory_act.html.twig', [
                'doc' => $doc, 'campaign' => $c, 'summary' => $summary, 's' => $sections, 'org' => $this->orgName,
            ], 'A4', 'landscape');
            $sha = $this->storage->write(sprintf('documents/%s/%s.pdf', date('Y'), $doc->getNumber()), $pdf);
            $doc->attachPdf(sprintf('documents/%s/%s.pdf', date('Y'), $doc->getNumber()), $sha);
            $this->audit->log('inventory.act', [
                'objectType' => 'inventory_campaign', 'objectId' => (string) $c->getId(), 'objectLabel' => (string) $c, 'document' => $doc->getNumber(),
            ]);
            $this->em->flush();

            return $doc;
        });
    }

    private function renderPdf(HandoverDocument $doc): void
    {
        $content = $this->pdf->render('pdf/handover.html.twig', ['doc' => $doc]);
        $rel = sprintf('documents/%s/%s.pdf', $doc->getCreatedAt()->format('Y'), $doc->getNumber());
        $sha = $this->storage->write($rel, $content);
        $doc->attachPdf($rel, $sha);
    }

    public function pdfContent(HandoverDocument $doc): string
    {
        if (!$doc->getPdfPath()) {
            throw new BusinessException('PDF акта не сформирован');
        }
        $content = $this->storage->read($doc->getPdfPath());
        if (hash('sha256', $content) !== $doc->getPdfSha256()) {
            throw new \RuntimeException('Контрольная сумма PDF не совпадает — файл акта изменен вне системы');
        }

        return $content;
    }

    /**
     * Прием подписи (CMS из NCALayer), проверка и подтверждение закрепления (п. 13.1, шаги 5–6).
     */
    public function attachSignature(HandoverDocument $doc, string $cmsBase64, string $via = 'web'): DigitalSignature
    {
        if ($doc->getStatus() === HandoverDocument::STATUS_SIGNED) {
            throw new BusinessException('Документ уже подписан');
        }
        if ($doc->getSignDeadline() && $doc->getSignDeadline() < new \DateTimeImmutable('today')) {
            $doc->setStatus(HandoverDocument::STATUS_EXPIRED);
            $this->em->flush();
            throw new BusinessException('Срок подписания акта истек. Обратитесь в IT для переоформления.');
        }
        $pdf = $this->pdfContent($doc);
        $result = $this->verifier->verify($cmsBase64, $pdf);

        $sig = new DigitalSignature($doc, preg_replace('/\s+/', '', $cmsBase64), hash('sha256', $pdf));
        $sig->applyVerification($result);
        $this->em->persist($sig);
        $doc->getSignatures()->add($sig);

        // Совпадение подписанта с получателем по ИИН невозможно проверить без ИИН в карточке:
        // в протокол проверки пишем ФИО из сертификата для ручного контроля.
        if ($sig->isValid()) {
            $doc->setStatus(HandoverDocument::STATUS_SIGNED)->setSignedAt(new \DateTimeImmutable());
            foreach ($this->em->getRepository(AssetAssignment::class)->findBy(['issueDocument' => $doc]) as $as) {
                $as->setConfirmed(true);
            }
        } elseif ($sig->getVerificationStatus() === DigitalSignature::VERIFY_INVALID) {
            $doc->setStatus(HandoverDocument::STATUS_REJECTED);
        }

        $this->audit->log('signature.sign', [
            'objectType' => 'handover_document', 'objectId' => $doc->getId()->toRfc4122(), 'objectLabel' => $doc->getNumber(),
            'new' => ['verification' => $sig->getVerificationStatus(), 'subject' => $sig->getCertSubject(), 'serial' => $sig->getCertSerial()],
            'document' => $doc->getNumber(), 'source' => $via,
            'username' => $via === 'sign_link' ? ('ЭЦП: ' . ($sig->getCertSubject() ?? 'неизвестный сертификат')) : null,
        ]);
        $this->em->flush();

        return $sig;
    }

    /** Ручное подтверждение подписи IT (при статусе «Требуется проверка» в базовом режиме). */
    public function confirmSignatureManually(DigitalSignature $sig, User $by, string $comment): void
    {
        if ($sig->getVerificationStatus() !== DigitalSignature::VERIFY_PENDING) {
            throw new BusinessException('Подтверждение возможно только для подписи со статусом «Требуется проверка»');
        }
        $details = $sig->getVerificationDetails() + ['manual_confirmation' => ['by' => $by->getUsername(), 'at' => date(DATE_ATOM), 'comment' => $comment]];
        $r = new Signature\VerificationResult(DigitalSignature::VERIFY_VALID, $details, $sig->getCertSubject(), $sig->getCertIssuer(), $sig->getCertSerial(), $sig->getIin(), $sig->getCertValidFrom(), $sig->getCertValidTo(), $sig->getSignedAt());
        $sig->applyVerification($r);
        $doc = $sig->getDocument();
        $doc->setStatus(HandoverDocument::STATUS_SIGNED)->setSignedAt(new \DateTimeImmutable());
        foreach ($this->em->getRepository(AssetAssignment::class)->findBy(['issueDocument' => $doc]) as $as) {
            $as->setConfirmed(true);
        }
        $this->audit->log('signature.manual_confirm', [
            'objectType' => 'handover_document', 'objectId' => $doc->getId()->toRfc4122(), 'objectLabel' => $doc->getNumber(),
            'comment' => $comment, 'document' => $doc->getNumber(),
        ]);
        $this->em->flush();
    }
}
