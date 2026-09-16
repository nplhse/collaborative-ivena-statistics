<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Insights;

use App\Statistics\Application\Insights\InsightDirectoryPage;
use PHPUnit\Framework\TestCase;

final class InsightDirectoryPageTest extends TestCase
{
    public function testExposesPaginatorHelpers(): void
    {
        $page = new InsightDirectoryPage([], 40, 2, 25, 40);

        self::assertSame(2, $page->getCurrentPage());
        self::assertSame(2, $page->getLastPage());
        self::assertTrue($page->hasPreviousPage());
        self::assertSame(1, $page->getPreviousPage());
        self::assertFalse($page->hasNextPage());
        self::assertSame(2, $page->getNextPage());
        self::assertTrue($page->hasToPaginate());
        self::assertSame(40, $page->getNumResults());
    }

    public function testLastPageNeverDropsBelowOne(): void
    {
        $page = new InsightDirectoryPage([], 0, 1, 0, 0);

        self::assertSame(1, $page->getLastPage());
        self::assertFalse($page->hasToPaginate());
    }
}
