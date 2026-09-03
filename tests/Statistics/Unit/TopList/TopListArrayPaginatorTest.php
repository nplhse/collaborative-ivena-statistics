<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\TopList;

use App\Statistics\Application\TopList\TopListArrayPaginator;
use PHPUnit\Framework\TestCase;

final class TopListArrayPaginatorTest extends TestCase
{
    public function testFromCountClampsPageAndExposesNavigation(): void
    {
        $paginator = TopListArrayPaginator::fromCount(120, 99, 50);

        self::assertSame(3, $paginator->getCurrentPage());
        self::assertSame(3, $paginator->getLastPage());
        self::assertSame(50, $paginator->getPageSize());
        self::assertSame(120, $paginator->getNumResults());
        self::assertTrue($paginator->hasToPaginate());
        self::assertTrue($paginator->hasPreviousPage());
        self::assertFalse($paginator->hasNextPage());
        self::assertSame(2, $paginator->getPreviousPage());
        self::assertSame(3, $paginator->getNextPage());
    }

    public function testEmptyResultStaysOnFirstPage(): void
    {
        $paginator = TopListArrayPaginator::fromCount(0, 4, 50);

        self::assertSame(1, $paginator->getCurrentPage());
        self::assertSame(1, $paginator->getLastPage());
        self::assertFalse($paginator->hasToPaginate());
        self::assertFalse($paginator->hasPreviousPage());
        self::assertFalse($paginator->hasNextPage());
    }
}
