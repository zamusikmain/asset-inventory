<?php

namespace App\Util;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as OrmPaginator;

final class Paginator implements \IteratorAggregate, \Countable
{
    public readonly int $total;
    public readonly int $pages;
    private OrmPaginator $inner;

    public function __construct(QueryBuilder $qb, public readonly int $page = 1, public readonly int $perPage = 50)
    {
        $qb->setFirstResult(max(0, ($page - 1) * $perPage))->setMaxResults($perPage);
        $this->inner = new OrmPaginator($qb, true);
        $this->total = count($this->inner);
        $this->pages = max(1, (int) ceil($this->total / $perPage));
    }

    public function getIterator(): \Traversable
    {
        return $this->inner->getIterator();
    }

    public function count(): int
    {
        return iterator_count($this->inner->getIterator());
    }
}
