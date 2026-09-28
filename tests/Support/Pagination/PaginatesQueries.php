<?php

declare(strict_types=1);

namespace App\Tests\Support\Pagination;

use Doctrine\ORM\QueryBuilder;
use Symfony\UX\Pagination\NumberedPaginationInterface;
use Symfony\UX\Pagination\PaginatorInterface;

trait PaginatesQueries
{
    /**
     * @return NumberedPaginationInterface<mixed>
     */
    private function paginateQuery(QueryBuilder $query, int $perPage = 100): NumberedPaginationInterface
    {
        $paginator = self::getContainer()->get(PaginatorInterface::class);
        self::assertInstanceOf(PaginatorInterface::class, $paginator);

        return $paginator->query($query)->perPage($perPage)->paginate();
    }
}
