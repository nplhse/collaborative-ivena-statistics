<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\UI\Http\Controller\ClosureRecurringProfileTableState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ClosureRecurringProfileTableStateTest extends TestCase
{
    public function testFromRequestNormalizesPaginationSortAndSearch(): void
    {
        $request = Request::create('/', 'GET', [
            ClosureRecurringProfileTableState::PAGE_PARAM => '2',
            ClosureRecurringProfileTableState::LIMIT_PARAM => '50',
            ClosureRecurringProfileTableState::SORT_PARAM => 'hospital',
            ClosureRecurringProfileTableState::ORDER_PARAM => 'ASC',
            ClosureRecurringProfileTableState::SEARCH_PARAM => '  inner  ',
        ]);

        $state = ClosureRecurringProfileTableState::fromRequest($request);
        self::assertSame(2, $state->page);
        self::assertSame(50, $state->limit);
        self::assertSame('hospital', $state->sortBy);
        self::assertSame('asc', $state->orderBy);
        self::assertSame('inner', $state->profileQ);

        $query = $state->toTableQuery();
        self::assertSame(2, $query->page);
        self::assertSame(50, $query->limit);
        self::assertSame('hospital', $query->sortBy);
        self::assertSame('asc', $query->orderBy);
        self::assertSame('inner', $query->profileQ);
    }

    public function testFromRequestFallsBackOnInvalidValuesAndTruncatesSearch(): void
    {
        $longSearch = str_repeat('x', 250);
        $request = Request::create('/', 'GET', [
            ClosureRecurringProfileTableState::PAGE_PARAM => '0',
            ClosureRecurringProfileTableState::LIMIT_PARAM => '999',
            ClosureRecurringProfileTableState::SORT_PARAM => 'not-a-column',
            ClosureRecurringProfileTableState::ORDER_PARAM => 'sideways',
            ClosureRecurringProfileTableState::SEARCH_PARAM => $longSearch,
        ]);

        $state = ClosureRecurringProfileTableState::fromRequest($request, 100);
        self::assertSame(1, $state->page);
        self::assertSame(100, $state->limit);
        self::assertSame(ClosureRecurringProfileTableState::DEFAULT_SORT, $state->sortBy);
        self::assertSame(ClosureRecurringProfileTableState::DEFAULT_ORDER, $state->orderBy);
        self::assertSame(200, strlen($state->profileQ));
    }
}
