<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\Pivot\PivotPresentationMapper;
use App\Statistics\Application\Pivot\PivotResult;
use PHPUnit\Framework\TestCase;

final class PivotPresentationMapperTest extends TestCase
{
    public function testMapsAbsoluteValuesWithFormatter(): void
    {
        $mapper = new PivotPresentationMapper();
        $pivot = new PivotResult(
            ['Row A'],
            ['Col A', 'Col B'],
            [[4.0, 6.0]],
            [10.0],
            [4.0, 6.0],
            10.0,
        );

        $result = $mapper->map($pivot, false, static fn (float $v): string => (string) (int) round($v));

        self::assertSame([['4', '6']], $result->matrix);
        self::assertSame(['10'], $result->rowTotals);
        self::assertSame(['4', '6'], $result->columnTotals);
        self::assertSame('10', $result->grandTotal);
    }

    public function testMapsRowPercentWithHundredTotals(): void
    {
        $mapper = new PivotPresentationMapper();
        $pivot = new PivotResult(
            ['Row A'],
            ['Col A', 'Col B'],
            [[1.0, 3.0]],
            [4.0],
            [1.0, 3.0],
            4.0,
        );

        $result = $mapper->map($pivot, true, static fn (float $v): string => (string) $v);

        self::assertSame([['25.0%', '75.0%']], $result->matrix);
        self::assertSame(['100.0%'], $result->rowTotals);
        self::assertSame(['100.0%', '100.0%'], $result->columnTotals);
        self::assertSame('100.0%', $result->grandTotal);
    }
}
