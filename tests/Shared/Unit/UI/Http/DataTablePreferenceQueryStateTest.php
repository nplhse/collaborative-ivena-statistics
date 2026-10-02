<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\UI\Http;

use App\Shared\UI\Http\DataTablePreferenceQueryState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DataTablePreferenceQueryStateTest extends TestCase
{
    public function testAbsentValuesRemainNull(): void
    {
        $state = DataTablePreferenceQueryState::fromRequest(Request::create('/table'));

        self::assertNull($state->visibleColumns);
        self::assertNull($state->columnOrder);
        self::assertNull($state->pageSize);
    }

    public function testParsesPresentationOnlyAndRejectsArrayInput(): void
    {
        $state = DataTablePreferenceQueryState::fromRequest(Request::create('/table', 'GET', [
            'columns' => 'reason,start,reason,unknown',
            'columnOrder' => 'reason,start',
            'limit' => '50',
            'scope' => 'hospital',
            'period' => 'year',
        ]));

        self::assertSame(['reason', 'start', 'unknown'], $state->visibleColumns);
        self::assertSame(['reason', 'start'], $state->columnOrder);
        self::assertSame(50, $state->pageSize);

        $invalid = DataTablePreferenceQueryState::fromRequest(Request::create('/table', 'GET', [
            'columns' => ['reason'],
            'columnOrder' => ['reason'],
            'limit' => ['50'],
        ]));
        self::assertSame([], $invalid->visibleColumns);
        self::assertSame([], $invalid->columnOrder);
        self::assertNull($invalid->pageSize);
    }

    public function testReadsCustomQueryKeys(): void
    {
        $state = DataTablePreferenceQueryState::fromRequest(
            Request::create('/table', 'GET', [
                'unitsColumns' => 'name,duration',
                'unitsColumnOrder' => 'duration,name',
                'unitsLimit' => '100',
                'columns' => 'hospital',
                'limit' => '25',
            ]),
            'unitsColumns',
            'unitsColumnOrder',
            'unitsLimit',
        );

        self::assertSame(['name', 'duration'], $state->visibleColumns);
        self::assertSame(['duration', 'name'], $state->columnOrder);
        self::assertSame(100, $state->pageSize);
    }
}
