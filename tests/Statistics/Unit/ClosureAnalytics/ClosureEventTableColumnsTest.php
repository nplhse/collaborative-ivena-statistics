<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\UI\Twig\ClosureEventTableColumns;
use App\Statistics\ClosureAnalytics\UI\Twig\ClosureIntervalTableColumns;
use PHPUnit\Framework\TestCase;

final class ClosureEventTableColumnsTest extends TestCase
{
    public function testDefaultEventColumnOrderAndShortLabels(): void
    {
        $columns = ClosureEventTableColumns::columns();

        self::assertSame([
            'startsAt',
            'endsAt',
            'hospital',
            'event',
            'specialities',
            'departments',
            'closureCount',
            'careLevels',
            'summedMinutes',
            'actualMinutes',
            'reasons',
            'closureUnits',
        ], array_column($columns, 'key'));
        self::assertSame('stats.closure.table.count', $columns[6]['label']);
        self::assertSame('stats.closure.table.sum', $columns[8]['label']);
        self::assertSame('stats.closure.table.duration', $columns[9]['label']);
        self::assertSame('stats.closure.table.reason', $columns[10]['label']);
        self::assertSame(
            array_column($columns, 'key'),
            new ClosureEventTableColumns()->preferenceSchema()->columnOrder,
        );
    }

    public function testDefaultIntervalColumnOrderMatchesEventLayout(): void
    {
        self::assertSame([
            'startsAt',
            'endsAt',
            'hospital',
            'event',
            'speciality',
            'department',
            'careLevel',
            'durationMinutes',
            'reason',
            'closureUnit',
        ], array_column(ClosureIntervalTableColumns::columns(), 'key'));
    }
}
