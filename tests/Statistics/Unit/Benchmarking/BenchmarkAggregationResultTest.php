<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Benchmarking;

use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkAggregationResult;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkSideCounts;
use PHPUnit\Framework\TestCase;

final class BenchmarkAggregationResultTest extends TestCase
{
    public function testEmptyFactoryCreatesZeroedSides(): void
    {
        $result = BenchmarkAggregationResult::empty();

        self::assertSame(0, $result->primary->total);
        self::assertSame(0, $result->comparison->total);
        self::assertSame([], $result->distributionRows);
        self::assertTrue($result->hasEmptyPrimaryScope());
    }

    public function testHasEmptyPrimaryScopeIsFalseWhenEitherSideHasCases(): void
    {
        $result = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
                0, 0, 0, 0, 0, 0, null, null, null,
            ),
            BenchmarkSideCounts::empty(),
            [],
        );

        self::assertFalse($result->hasEmptyPrimaryScope());
    }
}
