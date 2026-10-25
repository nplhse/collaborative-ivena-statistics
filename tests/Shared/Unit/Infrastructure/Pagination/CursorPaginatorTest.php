<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Pagination;

use App\Shared\Infrastructure\Pagination\CursorPaginator;
use PHPUnit\Framework\TestCase;

final class CursorPaginatorTest extends TestCase
{
    public function testImplementsTheUxCursorContract(): void
    {
        $paginator = new CursorPaginator([['id' => 1], ['id' => 2]], 2, 'next', 'prev', 9, 'current');

        self::assertSame([['id' => 1], ['id' => 2]], $paginator->getItems());
        self::assertSame([['id' => 1], ['id' => 2]], iterator_to_array($paginator));
        self::assertCount(2, $paginator);
        self::assertFalse($paginator->isEmpty());
        self::assertSame(2, $paginator->getItemsPerPage());
        self::assertSame('current', $paginator->getCursor());
        self::assertTrue($paginator->hasNext());
        self::assertTrue($paginator->hasPrevious());
        self::assertSame('next', $paginator->getNextCursor());
        self::assertSame('prev', $paginator->getPreviousCursor());
        self::assertSame(9, $paginator->getEstimatedNumResults());
        self::assertSame(5, $paginator->getEstimatedLastPage());
        self::assertSame('', $paginator->getInfo());
        self::assertSame('prev', $paginator->jsonSerialize()['previous_cursor']);
        self::assertSame(9, $paginator->jsonSerialize()['estimated_total']);
    }

    public function testBoundaryPagesHaveNoLinks(): void
    {
        $paginator = new CursorPaginator([], 25, null);

        self::assertTrue($paginator->isEmpty());
        self::assertFalse($paginator->hasNext());
        self::assertFalse($paginator->hasPrevious());
        self::assertNull($paginator->getNextUrl());
        self::assertNull($paginator->getPreviousUrl());
        self::assertNull($paginator->getEstimatedLastPage());
    }

    public function testUrlsNeedAGenerator(): void
    {
        $paginator = new CursorPaginator([['id' => 1]], 25, 'next', 'prev');

        try {
            $paginator->getNextUrl();
            self::fail('Expected a LogicException without a URL generator.');
        } catch (\LogicException) {
        }

        $withUrls = $paginator->withCursorUrlGenerator(static fn (string $cursor): string => '/list?cursor='.$cursor);

        self::assertSame('/list?cursor=next', $withUrls->getNextUrl());
        self::assertSame('/list?cursor=prev', $withUrls->getPreviousUrl());
        self::assertSame([['id' => 1]], $withUrls->getItems());
    }
}
