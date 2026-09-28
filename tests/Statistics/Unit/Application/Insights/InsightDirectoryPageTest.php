<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Insights;

use App\Statistics\Application\Insights\InsightDirectoryPage;
use PHPUnit\Framework\TestCase;
use Symfony\UX\Pagination\Test\PaginatorFactory;

final class InsightDirectoryPageTest extends TestCase
{
    public function testExposesTheNumberedPage(): void
    {
        $pagination = PaginatorFactory::create()
            ->query(array_fill(0, 40, 1))
            ->perPage(25)
            ->paginate(page: 2);
        $page = new InsightDirectoryPage([], 40, $pagination);

        self::assertSame(2, $page->pagination->getCurrentPage());
        self::assertSame(2, $page->pagination->getTotalPages());
        self::assertTrue($page->pagination->hasPrevious());
        self::assertFalse($page->pagination->hasNext());
        self::assertSame(40, $page->pagination->getTotalItems());
        self::assertSame(40, $page->totalAllocations);
    }

    public function testEmptyPageHasASinglePageAndNoNavigation(): void
    {
        $pagination = PaginatorFactory::create()
            ->query([])
            ->perPage(25)
            ->paginate();
        $page = new InsightDirectoryPage([], 0, $pagination);

        self::assertSame(1, $page->pagination->getTotalPages());
        self::assertFalse($page->pagination->hasPrevious());
        self::assertFalse($page->pagination->hasNext());
        self::assertSame([], $page->rows);
    }
}
