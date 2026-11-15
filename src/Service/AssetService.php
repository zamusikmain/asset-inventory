<?php

namespace App\Service;

use App\Entity\Asset;
use App\Entity\AssetAssignment;
use App\Entity\AssetMovement;
use App\Entity\Employee;
use App\Entity\HandoverDocument;
use App\Entity\Location;
use App\Entity\User;
use App\Enum\AssetStatus;
use App\Exception\BusinessException;
use App\Service\Integration\IntegrationOutbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Все изменения статуса, сотрудника и местоположения проходят через этот сервис:
 * контроль переходов (п. 7), история (asset_movements) и аудит (п. 18).
 */
class AssetService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly Security $security,
        private readonly IntegrationOutbox $outbox,
    ) {
    }

    public static function snapshot(Asset $a): array
    {
        $attrs = [];
        foreach ($a->getAttributeValues() as $v) {
            $attrs['attr.' . $v->getDefinition()->getCode()] = $v->getValue();
        }

        return [
            'inventoryNumber' => $a->getInventoryNumber(),
            'fullInventoryNumber' => $a->getFullInventoryNumber(),
            'barcode' => $a->getBarcode(),
            'type' => $a->getType()?->__toString(),
            'name' => $a->getName(),
            'manufacturer' => $a->getManufacturer(),
            'model' => $a->getModel(),
            'serialNumber' => $a->getSerialNumber(),
            'status' => $a->getStatus()->value,
            'employee' => $a->getEmployee()?->getName(),
            'location' => $a->getLocation()?->getPathString(),
            'bundle' => $a->getBundle()?->getName(),
            'condition' => $a->getCondition(),
            'comment' => $a->getComment(),
            'hasRecoveryKey' => $a->hasRecoveryKey(),
            'archived' => $a->isArchived(),
            'external1cId' => $a->getExternal1cId(),
        ] + $attrs;
    }

    private function currentUser(): ?User
    {
        $u = $this->security->getUser();

        return $u instanceof User ? $u : null;
    }

    private function isAdmin(): bool
    {
        return $this->security->isGranted('ROLE_ADMIN');
    }

    public function create(Asset $asset, string $source = 'web', ?string $comment = null): void
    {
        if ($asset->getBarcode() === '') {
            $asset->setBarcode(null);
        }
        $asset->setSourceSystem($source === 'import' ? 'excel' : $asset->getSourceSystem());
        $this->em->persist($asset);

        $mv = (new AssetMovement($asset, AssetMovement::TYPE_CREATED, $this->currentUser(), $source))
            ->status(null, $asset->getStatus())
            ->employee(null, $asset->getEmployee())
            ->location(null, $asset->getLocation())
            ->withComment($comment);
        $this->em->persist($mv);

        if ($asset->getEmployee()) {
            $this->em->persist(new AssetAssignment($asset, $asset->getEmployee(), $this->currentUser()));
        }

        $this->audit->log('asset.create', [
            'objectType' => 'asset', 'objectId' => $asset->getId()->toRfc4122(), 'objectLabel' => (string) $asset,
            'new' => self::snapshot($asset), 'comment' => $comment, 'source' => $source === 'import' ? 'import' : null,
        ]);
        $this->outbox->assetChanged($asset, 'created');
    }

    /**
     * Сохранение редактирования карточки (без смены статуса/сотрудника/места — для них отдельные операции).
     */
    public function saveEdit(Asset $asset, array $before, string $source = 'web'): void
    {
        [$old, $new] = AuditLogger::diff($before, self::snapshot($asset));
        if (!$old && !$new) {
            return;
        }
        $this->audit->log('asset.update', [
            'objectType' => 'asset', 'objectId' => $asset->getId()->toRfc4122(), 'objectLabel' => (string) $asset,
            'old' => $old, 'new' => $new, 'source' => $source === 'import' ? 'import' : null,
        ]);
        $this->outbox->assetChanged($asset, 'updated');
    }

    public function assertTransition(Asset $asset, AssetStatus $to): void
    {
        $from = $asset->getStatus();
        if (!$from->canTransitionTo($to, $this->isAdmin())) {
            $hint = $from->isTerminal() ? ' Восстановление статуса выполняет администратор.' : '';
            throw new BusinessException(sprintf(
                'Переход «%s» → «%s» для %s недопустим.%s',
                $from->label(), $to->label(), $asset->getInventoryNumber(), $hint,
            ));
        }
    }

    public function changeStatus(Asset $asset, AssetStatus $to, ?string $comment = null, string $source = 'web', ?HandoverDocument $doc = null): void
    {
        $from = $asset->getStatus();
        if ($from === $to) {
            return;
        }
        $this->assertTransition($asset, $to);

        $asset->setStatus($to);
        // При списании/архивации/утере актив откреплен от сотрудника
        if (in_array($to, [AssetStatus::WrittenOff, AssetStatus::Archived, AssetStatus::Lost, AssetStatus::InStock], true) && $asset->getEmployee()) {
            $this->closeAssignment($asset, null);
            $prevEmp = $asset->getEmployee();
            $asset->setEmployee(null);
            $mv = (new AssetMovement($asset, AssetMovement::TYPE_STATUS, $this->currentUser(), $source))
                ->status($from, $to)->employee($prevEmp, null)->location($asset->getLocation(), $asset->getLocation())
                ->withComment($comment)->withDocument($doc);
        } else {
            $mv = (new AssetMovement($asset, AssetMovement::TYPE_STATUS, $this->currentUser(), $source))
                ->status($from, $to)->employee($asset->getEmployee(), $asset->getEmployee())
                ->location($asset->getLocation(), $asset->getLocation())
                ->withComment($comment)->withDocument($doc);
        }
        if ($to === AssetStatus::Archived) {
            $asset->setArchived(true);
        } elseif ($from === AssetStatus::Archived) {
            $asset->setArchived(false);
        }
        $this->em->persist($mv);

        $this->audit->log($from->isTerminal() && !$to->isTerminal() ? 'asset.status_restore' : 'asset.status', [
            'objectType' => 'asset', 'objectId' => $asset->getId()->toRfc4122(), 'objectLabel' => (string) $asset,
            'old' => ['status' => $from->value], 'new' => ['status' => $to->value], 'comment' => $comment,
            'document' => $doc?->getNumber(),
        ]);
        $this->outbox->assetChanged($asset, 'status');
    }

    /**
     * Проверка доступности актива для выдачи (п. 13.1, шаг 2).
     */
    public function checkIssuable(Asset $asset, Employee $to): ?string
    {
        if ($asset->isArchived() || $asset->getStatus()->isTerminal()) {
            return sprintf('%s: актив списан/в архиве — выдача невозможна без восстановления администратором', $asset->getInventoryNumber());
        }
        if ($asset->getStatus() === AssetStatus::Repair) {
            return sprintf('%s: актив в ремонте — закрепление невозможно', $asset->getInventoryNumber());
        }
        if (in_array($asset->getStatus(), [AssetStatus::Lost, AssetStatus::Damaged, AssetStatus::TempIssued], true)) {
            return sprintf('%s: статус «%s» не допускает выдачу', $asset->getInventoryNumber(), $asset->getStatus()->label());
        }
        if ($asset->getEmployee() && $asset->getEmployee() !== $to) {
            return sprintf('%s: уже закреплен за %s — сначала оформите возврат', $asset->getInventoryNumber(), $asset->getEmployee()->getName());
        }
        if ($asset->getEmployee() === $to) {
            return sprintf('%s: уже закреплен за этим сотрудником', $asset->getInventoryNumber());
        }
        if (!$asset->getStatus()->isIssuable() && $asset->getStatus() !== AssetStatus::Created && $asset->getStatus() !== AssetStatus::Review) {
            return sprintf('%s: статус «%s» не допускает выдачу', $asset->getInventoryNumber(), $asset->getStatus()->label());
        }

        return null;
    }

    public function assign(Asset $asset, Employee $employee, ?HandoverDocument $doc, ?string $comment = null): AssetAssignment
    {
        if ($err = $this->checkIssuable($asset, $employee)) {
            throw new BusinessException($err);
        }
        $from = $asset->getStatus();
        // Created/Review → сначала на склад (контролируемый путь)
        $to = AssetStatus::Assigned;
        $asset->setStatus($to);
        $asset->setEmployee($employee);

        $assignment = new AssetAssignment($asset, $employee, $this->currentUser());
        $assignment->setIssueDocument($doc);
        $this->em->persist($assignment);

        $mv = (new AssetMovement($asset, AssetMovement::TYPE_ASSIGN, $this->currentUser()))
            ->status($from, $to)->employee(null, $employee)
            ->location($asset->getLocation(), $asset->getLocation())
            ->withComment($comment)->withDocument($doc);
        $this->em->persist($mv);

        $this->audit->log('asset.assign', [
            'objectType' => 'asset', 'objectId' => $asset->getId()->toRfc4122(), 'objectLabel' => (string) $asset,
            'old' => ['status' => $from->value, 'employee' => null],
            'new' => ['status' => $to->value, 'employee' => $employee->getName()],
            'document' => $doc?->getNumber(), 'comment' => $comment,
        ]);
        $this->outbox->assetChanged($asset, 'assigned');

        return $assignment;
    }

    public function returnAsset(Asset $asset, ?HandoverDocument $doc, ?Location $toLocation = null, AssetStatus $toStatus = AssetStatus::InStock, ?string $comment = null): void
    {
        $employee = $asset->getEmployee();
        if (!$employee) {
            throw new BusinessException(sprintf('%s не закреплен за сотрудником', $asset->getInventoryNumber()));
        }
        if (!in_array($toStatus, [AssetStatus::InStock, AssetStatus::Damaged, AssetStatus::Repair], true)) {
            throw new BusinessException('При возврате допустимы статусы: На складе, Повреждено, В ремонте');
        }
        $from = $asset->getStatus();
        $fromLoc = $asset->getLocation();

        $this->closeAssignment($asset, $doc);
        $asset->setEmployee(null);
        $asset->setStatus($toStatus);
        if ($toLocation) {
            $asset->setLocation($toLocation);
        }

        $mv = (new AssetMovement($asset, AssetMovement::TYPE_RETURN, $this->currentUser()))
            ->status($from, $toStatus)->employee($employee, null)
            ->location($fromLoc, $asset->getLocation())
            ->withComment($comment)->withDocument($doc);
        $this->em->persist($mv);

        $this->audit->log('asset.return', [
            'objectType' => 'asset', 'objectId' => $asset->getId()->toRfc4122(), 'objectLabel' => (string) $asset,
            'old' => ['status' => $from->value, 'employee' => $employee->getName(), 'location' => $fromLoc?->getPathString()],
            'new' => ['status' => $toStatus->value, 'employee' => null, 'location' => $asset->getLocation()?->getPathString()],
            'document' => $doc?->getNumber(), 'comment' => $comment,
        ]);
        $this->outbox->assetChanged($asset, 'returned');
    }

    public function move(Asset $asset, ?Location $to, ?string $comment = null, string $source = 'web', bool $setInstalled = false): void
    {
        $from = $asset->getLocation();
        if ($from === $to) {
            return;
        }
        if ($asset->getStatus()->isTerminal()) {
            throw new BusinessException(sprintf('%s списан/в архиве — перемещение невозможно', $asset->getInventoryNumber()));
        }
        $fromStatus = $asset->getStatus();
        $asset->setLocation($to);
        $toStatus = $fromStatus;
        if ($setInstalled && $to && !$asset->getEmployee() && $fromStatus->canTransitionTo(AssetStatus::Installed)) {
            $toStatus = AssetStatus::Installed;
            $asset->setStatus($toStatus);
        }

        $type = $source === 'inventory' ? AssetMovement::TYPE_INVENTORY : AssetMovement::TYPE_MOVE;
        $mv = (new AssetMovement($asset, $type, $this->currentUser(), $source))
            ->status($fromStatus, $toStatus)
            ->employee($asset->getEmployee(), $asset->getEmployee())
            ->location($from, $to)->withComment($comment);
        $this->em->persist($mv);

        $this->audit->log($source === 'inventory' ? 'inventory.location_change' : 'asset.move', [
            'objectType' => 'asset', 'objectId' => $asset->getId()->toRfc4122(), 'objectLabel' => (string) $asset,
            'old' => ['location' => $from?->getPathString()], 'new' => ['location' => $to?->getPathString()],
            'comment' => $comment,
        ]);
        $this->outbox->assetChanged($asset, 'moved');
    }

    /**
     * Применение состояния из подтвержденного импорта (п. 8.2, шаг 7). Импорт — явная
     * массовая корректировка: переходы статусов не ограничиваются матрицей, но вся
     * разница фиксируется в истории и аудите.
     *
     * @param Employee|false|null $employee null — без изменений, false — открепить
     */
    public function applyImportState(Asset $asset, ?AssetStatus $status, Employee|false|null $employee, ?Location $location, string $comment): void
    {
        $fromStatus = $asset->getStatus();
        $fromEmp = $asset->getEmployee();
        $fromLoc = $asset->getLocation();

        if ($employee !== null) {
            $newEmp = $employee ?: null;
            if ($newEmp !== $fromEmp) {
                if ($fromEmp) {
                    $this->closeAssignment($asset, null);
                }
                $asset->setEmployee($newEmp);
                if ($newEmp) {
                    $this->em->persist(new AssetAssignment($asset, $newEmp, $this->currentUser()));
                    $status ??= AssetStatus::Assigned;
                }
            }
        }
        if ($status !== null) {
            if ($status !== AssetStatus::Assigned && $asset->getEmployee() && in_array($status, [AssetStatus::InStock, AssetStatus::WrittenOff, AssetStatus::Archived, AssetStatus::Lost], true)) {
                $this->closeAssignment($asset, null);
                $asset->setEmployee(null);
            }
            $asset->setStatus($status);
            $asset->setArchived($status === AssetStatus::Archived);
        }
        if ($location !== null) {
            $asset->setLocation($location);
        }
        if ($fromStatus === $asset->getStatus() && $fromEmp === $asset->getEmployee() && $fromLoc === $asset->getLocation()) {
            return;
        }
        $mv = (new AssetMovement($asset, AssetMovement::TYPE_IMPORT, $this->currentUser(), 'import'))
            ->status($fromStatus, $asset->getStatus())->employee($fromEmp, $asset->getEmployee())
            ->location($fromLoc, $asset->getLocation())->withComment($comment);
        $this->em->persist($mv);
        $this->audit->log('asset.import_state', [
            'objectType' => 'asset', 'objectId' => $asset->getId()->toRfc4122(), 'objectLabel' => (string) $asset,
            'old' => ['status' => $fromStatus->value, 'employee' => $fromEmp?->getName(), 'location' => $fromLoc?->getPathString()],
            'new' => ['status' => $asset->getStatus()->value, 'employee' => $asset->getEmployee()?->getName(), 'location' => $asset->getLocation()?->getPathString()],
            'comment' => $comment, 'source' => 'import',
        ]);
        $this->outbox->assetChanged($asset, 'imported');
    }

    private function closeAssignment(Asset $asset, ?HandoverDocument $doc): void
    {
        $active = $this->em->getRepository(AssetAssignment::class)->findOneBy(['asset' => $asset, 'returnedAt' => null]);
        if ($active) {
            $active->close($this->currentUser());
            $active->setReturnDocument($doc);
        }
    }

    /** @return list<AssetMovement> */
    public function history(Asset $asset): array
    {
        return $this->em->getRepository(AssetMovement::class)->findBy(['asset' => $asset], ['performedAt' => 'DESC', 'id' => 'DESC']);
    }
}
