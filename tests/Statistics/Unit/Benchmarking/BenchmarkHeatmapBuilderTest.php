<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Benchmarking;

use App\Statistics\Application\Mapping\AllocationStatsDayTimeBucketProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsShiftBucketProjectionCode;
use App\Statistics\Benchmarking\Application\BenchmarkHeatmapBuilder;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkAggregationResult;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkDistributionRow;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkSideCounts;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BenchmarkHeatmapBuilderTest extends TestCase
{
    private BenchmarkHeatmapBuilder $builder;

    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => str_contains($id, 'weekday.') ? substr($id, strrpos($id, '.') + 1) : $id,
        );

        $this->builder = new BenchmarkHeatmapBuilder($translator);
    }

    public function testBuildsDayTimeDeltaMatrixInWeekdayAndBucketOrder(): void
    {
        $result = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                100, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
                0, 0, 0, 0, 0, 0, null, null, null,
            ),
            new BenchmarkSideCounts(
                200, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
                0, 0, 0, 0, 0, 0, null, null, null,
            ),
            [
                new BenchmarkDistributionRow(
                    'day_time_heatmap',
                    '3',
                    (string) AllocationStatsDayTimeBucketProjectionCode::Morning->value,
                    20,
                    20,
                ),
                new BenchmarkDistributionRow(
                    'day_time_heatmap',
                    '1',
                    (string) AllocationStatsDayTimeBucketProjectionCode::Night->value,
                    0,
                    0,
                ),
            ],
        );

        $heatmap = $this->builder->buildDayTimeCaseDistribution($result);

        self::assertCount(7, $heatmap->rowLabels);
        self::assertSame('monday', $heatmap->rowLabels[0]);
        self::assertCount(4, $heatmap->columnLabels);
        self::assertSame(10.0, $heatmap->deltaMatrix[2][0]);
        self::assertSame(0.0, $heatmap->deltaMatrix[0][3]);
        self::assertSame(10.0, $heatmap->maxAbsDelta);
        self::assertSame(20.0, $heatmap->primaryShareMatrix[2][0]);
        self::assertSame(10.0, $heatmap->comparisonShareMatrix[2][0]);
    }

    public function testBuildsShiftDeltaMatrix(): void
    {
        $result = new BenchmarkAggregationResult(
            BenchmarkSideCounts::empty(),
            BenchmarkSideCounts::empty(),
            [
                new BenchmarkDistributionRow(
                    'shift_heatmap',
                    '5',
                    (string) AllocationStatsShiftBucketProjectionCode::LateShift->value,
                    10,
                    5,
                ),
            ],
        );

        $heatmap = $this->builder->buildShiftCaseDistribution($result);

        self::assertCount(7, $heatmap->rowLabels);
        self::assertCount(3, $heatmap->columnLabels);
        self::assertSame(0.0, $heatmap->deltaMatrix[0][0]);
    }
}
