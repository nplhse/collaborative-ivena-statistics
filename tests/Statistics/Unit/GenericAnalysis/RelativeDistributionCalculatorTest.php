<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\RelativeDistributionCalculator;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResult;
use PHPUnit\Framework\TestCase;

final class RelativeDistributionCalculatorTest extends TestCase
{
    private RelativeDistributionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new RelativeDistributionCalculator();
    }

    public function testPercentOfTotalAndPercentOfBucket(): void
    {
        $result = new AnalysisResult(
            rows: [
                GenericAnalysisTestFixtures::resultRow(1, 30, series: 'A'),
                GenericAnalysisTestFixtures::resultRow(1, 70, series: 'B'),
                GenericAnalysisTestFixtures::resultRow(2, 100, series: 'A'),
            ],
            grandTotal: 200,
            primaryDimensionKey: 'month',
            metricKeys: ['count', 'percent_of_total', 'percent_of_bucket'],
            seriesDimensionKey: 'urgency',
        );

        $enriched = $this->calculator->enrich($result, $result->metricKeys);

        self::assertSame(15.0, $enriched[0]['derivedMetrics']['percent_of_total']);
        self::assertSame(30.0, $enriched[0]['derivedMetrics']['percent_of_bucket']);
        self::assertSame(35.0, $enriched[1]['derivedMetrics']['percent_of_total']);
        self::assertSame(70.0, $enriched[1]['derivedMetrics']['percent_of_bucket']);
        self::assertSame(50.0, $enriched[2]['derivedMetrics']['percent_of_total']);
        self::assertSame(100.0, $enriched[2]['derivedMetrics']['percent_of_bucket']);
    }

    public function testPercentOfSeriesDividesByTheCasesOfTheSeries(): void
    {
        // Reference A: X 3, Y 1; others (B–E pooled by cases): X 4, Y 4 (#1346).
        $result = new AnalysisResult(
            rows: [
                GenericAnalysisTestFixtures::resultRow('X', 3, series: '1'),
                GenericAnalysisTestFixtures::resultRow('Y', 1, series: '1'),
                GenericAnalysisTestFixtures::resultRow('X', 4, series: 'others'),
                GenericAnalysisTestFixtures::resultRow('Y', 4, series: 'others'),
            ],
            grandTotal: 12,
            primaryDimensionKey: 'indication',
            metricKeys: ['count', 'percent_of_series'],
            seriesDimensionKey: 'reference_group',
        );

        $enriched = $this->calculator->enrich($result, $result->metricKeys);

        self::assertSame([75.0, 25.0, 50.0, 50.0], array_map(static fn (array $row): float => $row['derivedMetrics']['percent_of_series'], $enriched));
    }

    public function testSkipsRelativeMetricsWhenNotRequested(): void
    {
        $result = new AnalysisResult(
            rows: [GenericAnalysisTestFixtures::resultRow(1, 10)],
            grandTotal: 10,
            primaryDimensionKey: 'month',
            metricKeys: ['count'],
        );

        $enriched = $this->calculator->enrich($result, $result->metricKeys);

        self::assertSame([], $enriched[0]['derivedMetrics']);
    }
}
