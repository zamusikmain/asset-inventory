<?php

namespace App\Repository;

use App\Entity\Employee;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Employee> */
class EmployeeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Employee::class);
    }

    public function searchQuery(?string $q, ?string $status = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.department', 'd')->addSelect('d')
            ->leftJoin('e.office', 'o')->addSelect('o')
            ->orderBy('e.displayName', 'ASC');
        if ($q) {
            $qb->andWhere('LOWER(e.displayName) LIKE :q OR LOWER(e.fullNameRu) LIKE :q OR LOWER(e.fullNameEn) LIKE :q OR LOWER(e.email) LIKE :q OR LOWER(e.upn) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($q) . '%');
        }
        if ($status) {
            $qb->andWhere('e.status = :st')->setParameter('st', $status);
        }

        return $qb;
    }

    /**
     * Поиск сотрудника по ФИО ENG (DisplayName) или ФИО RUS — для импорта Excel.
     */
    public function findByAnyName(?string $en, ?string $ru): ?Employee
    {
        $norm = fn (?string $s) => $s ? mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s))) : null;
        $en = $norm($en);
        $ru = $norm($ru);
        if (!$en && !$ru) {
            return null;
        }
        $qb = $this->createQueryBuilder('e');
        $or = [];
        if ($en) {
            $or[] = 'LOWER(e.displayName) = :en OR LOWER(e.fullNameEn) = :en';
            $qb->setParameter('en', $en);
        }
        if ($ru) {
            $or[] = 'LOWER(e.fullNameRu) = :ru OR LOWER(e.extensionAttribute) = :ru';
            $qb->setParameter('ru', $ru);
        }
        $res = $qb->andWhere(implode(' OR ', $or))->setMaxResults(2)->getQuery()->getResult();

        return count($res) === 1 ? $res[0] : null;
    }
}
