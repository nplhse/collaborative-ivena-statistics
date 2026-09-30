<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\UI\Http\Controller\ClosureEventTableState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ClosureEventTableStateTest extends TestCase
{
    public function testUsesSafeDefaults(): void
    {
        $state = ClosureEventTableState::fromRequest(Request::create('/details', 'GET', [
            'page' => '-3',
            'limit' => '17',
            'sortBy' => 'DROP TABLE',
            'orderBy' => 'sideways',
        ]));

        self::assertSame(1, $state->page);
        self::assertSame(25, $state->limit);
        self::assertSame('startsAt', $state->sortBy);
        self::assertSame('desc', $state->orderBy);
        self::assertSame('events', $state->view);
    }

    public function testAcceptsAllowlistedTableState(): void
    {
        $state = ClosureEventTableState::fromRequest(Request::create('/details', 'GET', [
            'page' => '3',
            'limit' => '50',
            'sortBy' => 'actualMinutes',
            'orderBy' => 'ASC',
            'tableView' => 'events',
        ]));

        self::assertSame(3, $state->page);
        self::assertSame(50, $state->limit);
        self::assertSame('actualMinutes', $state->sortBy);
        self::assertSame('asc', $state->orderBy);
        self::assertSame('events', $state->view);
    }

    public function testAcceptsIntervalViewAndItsOwnSortAllowlist(): void
    {
        $state = ClosureEventTableState::fromRequest(Request::create('/details', 'GET', [
            'tableView' => 'intervals',
            'sortBy' => 'department',
            'orderBy' => 'asc',
        ]));

        self::assertSame('intervals', $state->view);
        self::assertSame('department', $state->sortBy);
        self::assertSame('asc', $state->orderBy);
    }

    public function testRejectsArrayParametersWithoutThrowing(): void
    {
        $state = ClosureEventTableState::fromRequest(Request::create('/details', 'GET', [
            'page' => ['2'],
            'limit' => ['100'],
            'sortBy' => ['actualMinutes'],
            'orderBy' => ['asc'],
        ]));

        self::assertSame(1, $state->page);
        self::assertSame(25, $state->limit);
        self::assertSame('startsAt', $state->sortBy);
        self::assertSame('desc', $state->orderBy);
    }
}
