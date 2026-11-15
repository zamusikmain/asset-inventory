<?php

namespace App\Repository;

use App\Entity\InventoryCampaign;
use App\Enum\CampaignStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryCampaign> */
class InventoryCampaignRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryCampaign::class);
    }

    /** @return list<InventoryCampaign> */
    public function findActive(): array
    {
        return $this->findBy(['status' => [CampaignStatus::Started, CampaignStatus::InProgress]], ['id' => 'DESC']);
    }

    public function nextNumber(): string
    {
        $year = date('Y');
        $n = (int) $this->getEntityManager()->getConnection()
            ->fetchOne("SELECT COUNT(*) FROM inventory_campaigns WHERE number LIKE :p", ['p' => "ИНВ-$year-%"]);

        return sprintf('ИНВ-%s-%03d', $year, $n + 1);
    }
}
