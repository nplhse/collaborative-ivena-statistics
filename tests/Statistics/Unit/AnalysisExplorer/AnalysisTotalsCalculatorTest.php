<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AnalysisTotalsCalculator;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Tests\Statistics\Support\AnalysisExplorerTestSupport;
use PHPUnit\Framework\TestCase;

final class AnalysisTotalsCalculatorTest extends TestCase
{
    use AnalysisExplorerTestSupport;

    private AnalysisTotalsCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = $this->createAnalysisTotalsCalculator();
    }

    public function testSumsAllocationCountAcrossRowsAndColumns(): void
    {
        $rows = [
            new AnalysisResultRow('2024-06', 'Jun 2024', '1', 'Male', ['allocation_count' => 4]),
            new AnalysisResultRow('2024-06', 'Jun 2024', '2', 'Female', ['allocation_count' => 6]),
            new AnalysisResultRow('2024-07', 'Jul 2024', '1', 'Male', ['allocation_count' => 3]),
        ];

        $totals = $this->calculator->calculate($rows, [AnalysisMetricKey::AllocationCount]);

        self::assertSame(13.0, $totals->grandFor(AnalysisMetricKey::AllocationCount));
        self::assertSame([
            '2024-06' => ['allocation_count' => 10.0],
            '2024-07' => ['allocation_count' => 3.0],
        ], $totals->byRow);
        self::assertSame([
            '1' => ['allocation_count' => 7.0],
            '2' => ['allocation_count' => 6.0],
        ], $totals->byColumn);
    }

    public function testRoundsPercentOfTotalGrandTotal(): void
    {
        $rows = [
            new AnalysisResultRow('a', 'A', null, null, [
                'allocation_count' => 1,
                'percent_of_total' => 33.333,
            ]),
            new AnalysisResultRow('b', 'B', null, null, [
                'allocation_count' => 2,
                'percent_of_total' => 66.667,
            ]),
        ];

        $totals = $this->calculator->calculate($rows, [
            AnalysisMetricKey::AllocationCount,
            AnalysisMetricKey::PercentOfTotal,
        ]);

        self::assertSame(100.0, $totals->grandFor(AnalysisMetricKey::PercentOfTotal));
        self::assertNull($totals->grandFor(AnalysisMetricKey::ResusRate));
    }

    public function testPercentOfTotalGrandTotalIsAlwaysOneHundred(): void
    {
        $rows = [
            new AnalysisResultRow('a', 'A', null, null, [
                'allocation_count' => 1,
                'percent_of_total' => 10.0,
            ]),
            new AnalysisResultRow('b', 'B', null, null, [
                'allocation_count' => 2,
                'percent_of_total' => 20.0,
            ]),
        ];

        $totals = $this->calculator->calculate($rows, [
            AnalysisMetricKey::AllocationCount,
            AnalysisMetricKey::PercentOfTotal,
        ]);

        self::assertSame(100.0, $totals->grandFor(AnalysisMetricKey::PercentOfTotal));
    }

    public function testRateMetricsAreNotSummed(): void
    {
        $rows = [
            new AnalysisResultRow('a', 'A', null, null, ['resus_rate' => 10.0]),
            new AnalysisResultRow('b', 'B', null, null, ['resus_rate' => 20.0]),
        ];

        $totals = $this->calculator->calculate($rows, [AnalysisMetricKey::ResusRate]);

        self::assertNull($totals->grandFor(AnalysisMetricKey::ResusRate));
    }

    public function testComputesWeightedAverageBedsGrandTotal(): void
    {
        $rows = [
            new AnalysisResultRow('a', 'A', null, null, [
                'hospital_count' => 10,
                'avg_beds' => 100.0,
            ]),
            new AnalysisResultRow('b', 'B', null, null, [
                'hospital_count' => 16,
                'avg_beds' => 200.0,
            ]),
        ];

        $totals = $this->calculator->calculate($rows, [
            AnalysisMetricKey::HospitalCount,
            AnalysisMetricKey::AvgBeds,
        ]);

        self::assertSame(26.0, $totals->grandFor(AnalysisMetricKey::HospitalCount));
        self::assertEqualsWithDelta(161.538, $totals->grandFor(AnalysisMetricKey::AvgBeds), 0.001);
    }

    public function testComputesMinAndMaxBedsGrandTotals(): void
    {
        $rows = [
            new AnalysisResultRow('a', 'A', null, null, ['min_beds' => 20.0, 'max_beds' => 80.0]),
            new AnalysisResultRow('b', 'B', null, null, ['min_beds' => 10.0, 'max_beds' => 120.0]),
        ];

        $totals = $this->calculator->calculate($rows, [
            AnalysisMetricKey::MinBeds,
            AnalysisMetricKey::MaxBeds,
        ]);

        self::assertSame(10.0, $totals->grandFor(AnalysisMetricKey::MinBeds));
        self::assertSame(120.0, $totals->grandFor(AnalysisMetricKey::MaxBeds));
    }
}
