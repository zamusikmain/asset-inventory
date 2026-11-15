<?php

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuditLog> */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    public function searchQuery(array $f): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')->orderBy('a.id', 'DESC');
        if (!empty($f['action'])) {
            $qb->andWhere('a.action LIKE :ac')->setParameter('ac', $f['action'] . '%');
        }
        if (!empty($f['user'])) {
            $qb->andWhere('LOWER(a.username) LIKE :u')->setParameter('u', '%' . mb_strtolower($f['user']) . '%');
        }
        if (!empty($f['object_type'])) {
            $qb->andWhere('a.objectType = :ot')->setParameter('ot', $f['object_type']);
        }
        if (!empty($f['object_id'])) {
            $qb->andWhere('a.objectId = :oid')->setParameter('oid', $f['object_id']);
        }
        if (!empty($f['from'])) {
            $qb->andWhere('a.occurredAt >= :from')->setParameter('from', new \DateTimeImmutable($f['from']));
        }
        if (!empty($f['to'])) {
            $qb->andWhere('a.occurredAt < :to')->setParameter('to', (new \DateTimeImmutable($f['to']))->modify('+1 day'));
        }

        return $qb;
    }

    /** @return list<AuditLog> */
    public function forObject(string $type, string $id, int $limit = 100): array
    {
        return $this->findBy(['objectType' => $type, 'objectId' => $id], ['id' => 'DESC'], $limit);
    }
}
