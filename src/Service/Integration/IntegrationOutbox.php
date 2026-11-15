<?php

namespace App\Service\Integration;

use App\Entity\Asset;
use App\Entity\IntegrationEvent;
use App\Entity\InventoryCampaign;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Outbox для будущего обмена с 1С (п. 17 ТЗ). В 1С уходят: фактический пользователь,
 * местоположение, результаты инвентаризации, перемещения и статус акта.
 * Модуль отключаемый: при INTEGRATION_1C_ENABLED=0 события не накапливаются.
 */
class IntegrationOutbox
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(bool:INTEGRATION_1C_ENABLED)%')]
        private readonly bool $enabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function assetChanged(Asset $a, string $event): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->em->persist(new IntegrationEvent('1c', 'out', 'asset.' . $event, 'asset', $a->getId()->toRfc4122(), [
            'external_1c_id' => $a->getExternal1cId(),
            'inventory_number' => $a->getInventoryNumber(),
            'full_inventory_number' => $a->getFullInventoryNumber(),
            'actual_user' => $a->getEmployee() ? [
                'external_1c_id' => $a->getEmployee()->getExternal1cId(),
                'full_name' => $a->getEmployee()->getName(),
            ] : null,
            'location' => $a->getLocation()?->getPathString(),
            'status' => $a->getStatus()->value,
            'sync_version' => $a->getSyncVersion(),
            'occurred_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]));
    }

    public function campaignApproved(InventoryCampaign $c, array $summary): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->em->persist(new IntegrationEvent('1c', 'out', 'inventory.approved', 'inventory_campaign', (string) $c->getId(), [
            'number' => $c->getNumber(),
            'name' => $c->getName(),
            'summary' => $summary,
        ]));
    }
}
