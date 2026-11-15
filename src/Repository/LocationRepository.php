<?php

namespace App\Repository;

use App\Entity\Location;
use App\Enum\LocationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Location> */
class LocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Location::class);
    }

    /** @return list<Location> */
    public function findRoots(): array
    {
        return $this->findBy(['parent' => null], ['name' => 'ASC']);
    }

    /**
     * Все узлы, отсортированные для отображения деревом (обход в глубину).
     *
     * @return list<array{node: Location, depth: int}>
     */
    public function getFlatTree(bool $onlyActive = false): array
    {
        $all = $this->createQueryBuilder('l')->orderBy('l.name', 'ASC')->getQuery()->getResult();
        $byParent = [];
        foreach ($all as $l) {
            if ($onlyActive && !$l->isActive()) {
                continue;
            }
            $byParent[$l->getParent()?->getId() ?? 0][] = $l;
        }
        $out = [];
        $walk = function (int $pid, int $depth) use (&$walk, &$out, $byParent) {
            foreach ($byParent[$pid] ?? [] as $n) {
                $out[] = ['node' => $n, 'depth' => $depth];
                $walk($n->getId(), $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    /** @return list<int> id узла и всех его потомков */
    public function getDescendantIds(Location $root): array
    {
        $sql = 'WITH RECURSIVE t AS (SELECT id FROM locations WHERE id = :id
                UNION ALL SELECT l.id FROM locations l JOIN t ON l.parent_id = t.id) SELECT id FROM t';

        return array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn($sql, ['id' => $root->getId()]));
    }

    /** @return list<Location> */
    public function findByType(LocationType $type): array
    {
        return $this->findBy(['type' => $type, 'active' => true], ['name' => 'ASC']);
    }

    /**
     * Нормализованный поиск по строке «Расположение» из Excel:
     * сначала точное совпадение по пути, затем по имени помещения/коду.
     */
    public function findByHumanPath(string $value): ?Location
    {
        $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
        if ($key === '') {
            return null;
        }
        $candidates = $this->createQueryBuilder('l')
            ->where('LOWER(l.name) = :k OR LOWER(l.code) = :k')
            ->setParameter('k', $key)
            ->getQuery()->getResult();
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        // путь «Алматы / БЦ Нурлы Тау / Офис 5 / 301»
        $parts = array_map(fn ($p) => mb_strtolower(trim($p)), preg_split('#\s*[/,>\\\\]\s*#u', $key));
        if (count($parts) > 1) {
            foreach ($this->findAll() as $l) {
                $path = array_map(fn (Location $n) => mb_strtolower($n->getName()), $l->getPath());
                if (array_slice($path, -count($parts)) === $parts) {
                    return $l;
                }
            }
        }

        return null;
    }
}
