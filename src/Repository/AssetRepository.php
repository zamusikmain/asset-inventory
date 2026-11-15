<?php

namespace App\Repository;

use App\Entity\Asset;
use App\Entity\Employee;
use App\Entity\InventoryCampaign;
use App\Enum\AssetStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Asset> */
class AssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly LocationRepository $locations)
    {
        parent::__construct($registry, Asset::class);
    }

    /** Поиск по коду сканера: инвентарный номер или штрихкод. Индексированный запрос (< 1 с). */
    public function findByCode(string $code): ?Asset
    {
        $code = Asset::normalizeCode($code);
        if ($code === '') {
            return null;
        }

        return $this->createQueryBuilder('a')
            ->where('a.inventoryNumber = :c OR a.barcode = :c')
            ->setParameter('c', $code)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Реестр с фильтрами. Фильтры: q, status, type, location, employee, archived.
     */
    public function registryQuery(array $f): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.type', 't')->addSelect('t')
            ->leftJoin('a.employee', 'e')->addSelect('e')
            ->leftJoin('a.location', 'l')->addSelect('l');

        if (!empty($f['q'])) {
            $qb->andWhere('LOWER(a.inventoryNumber) LIKE :q OR LOWER(a.fullInventoryNumber) LIKE :q OR LOWER(a.name) LIKE :q
                OR LOWER(a.serialNumber) LIKE :q OR LOWER(a.model) LIKE :q OR LOWER(e.displayName) LIKE :q OR LOWER(e.fullNameRu) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower(trim($f['q'])) . '%');
        }
        if (!empty($f['status']) && ($st = AssetStatus::tryFrom($f['status']))) {
            $qb->andWhere('a.status = :st')->setParameter('st', $st);
        }
        if (!empty($f['type'])) {
            $qb->andWhere('t.id = :t')->setParameter('t', (int) $f['type']);
        }
        if (!empty($f['category'])) {
            $qb->andWhere('t.category = :cat')->setParameter('cat', $f['category']);
        }
        if (!empty($f['location']) && ($loc = $this->locations->find((int) $f['location']))) {
            $qb->andWhere('l.id IN (:locs)')->setParameter('locs', $this->locations->getDescendantIds($loc));
        }
        if (!empty($f['employee'])) {
            $qb->andWhere('e.id = :emp')->setParameter('emp', $f['employee']);
        }
        if (empty($f['archived'])) {
            $qb->andWhere('a.archived = false');
        }
        // фильтры по столбцам реестра
        foreach (['inv' => 'a.inventoryNumber', 'full' => 'a.fullInventoryNumber', 'name' => 'a.name',
            'emp_en' => 'e.displayName', 'emp_ru' => 'e.fullNameRu', 'comment' => 'a.comment'] as $key => $field) {
            if (!empty($f[$key])) {
                $qb->andWhere(sprintf('LOWER(%s) LIKE :f_%s', $field, $key))->setParameter('f_' . $key, '%' . mb_strtolower(trim($f[$key])) . '%');
            }
        }
        if (!empty($f['date']) && ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $f['date']))) {
            $qb->andWhere('a.sourceDate = :f_date')->setParameter('f_date', $d, Types::DATE_IMMUTABLE);
        }
        if (isset($f['rk']) && in_array($f['rk'], ['0', '1'], true)) {
            $qb->andWhere('a.hasRecoveryKey = :f_rk')->setParameter('f_rk', $f['rk'] === '1');
        }

        $sort = ['inv' => 'a.inventoryNumber', 'full' => 'a.fullInventoryNumber', 'name' => 'a.name', 'status' => 'a.status',
            'date' => 'a.sourceDate', 'emp_en' => 'e.displayName', 'emp_ru' => 'e.fullNameRu', 'loc' => 'l.name',
            'comment' => 'a.comment', 'rk' => 'a.hasRecoveryKey', 'updated' => 'a.updatedAt'][$f['sort'] ?? ''] ?? 'a.inventoryNumber';
        $qb->orderBy($sort, ($f['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC');

        return $qb;
    }

    /** @return list<Asset> */
    public function findAssignedTo(Employee $e): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.employee = :e')->setParameter('e', $e)
            ->andWhere('a.archived = false')
            ->orderBy('a.inventoryNumber')
            ->getQuery()->getResult();
    }

    /**
     * Ожидаемый состав кампании: активы в охвате локаций и категорий, кроме списанных/архивных.
     *
     * @return list<Asset>
     */
    public function findForCampaign(InventoryCampaign $c): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.location', 'l')->addSelect('l')
            ->where('a.archived = false')
            ->andWhere('a.status NOT IN (:term)')
            ->setParameter('term', [AssetStatus::WrittenOff, AssetStatus::Archived, AssetStatus::Lost]);

        if (!$c->getLocations()->isEmpty()) {
            $ids = [];
            foreach ($c->getLocations() as $loc) {
                $ids = array_merge($ids, $this->locations->getDescendantIds($loc));
            }
            $qb->andWhere('l.id IN (:locs)')->setParameter('locs', array_unique($ids));
        }
        if (!$c->getAssetTypes()->isEmpty()) {
            $qb->andWhere('a.type IN (:types)')->setParameter('types', $c->getAssetTypes()->toArray());
        }

        return $qb->orderBy('a.inventoryNumber')->getQuery()->getResult();
    }

    /** @return array<string,int> статус => количество */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.status AS s, COUNT(a.id) AS n')
            ->where('a.archived = false')
            ->groupBy('a.status')
            ->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['s'] instanceof AssetStatus ? $r['s']->value : $r['s']] = (int) $r['n'];
        }

        return $out;
    }

    /** @return array<int,int> locationId => количество активов */
    public function countByLocationIds(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.location) AS lid, COUNT(a.id) AS n')
            ->where('a.location IN (:ids)')->setParameter('ids', $ids)
            ->andWhere('a.archived = false')
            ->groupBy('lid')
            ->getQuery()->getArrayResult();

        return array_column(array_map(fn ($r) => [(int) $r['lid'], (int) $r['n']], $rows), 1, 0);
    }
}
