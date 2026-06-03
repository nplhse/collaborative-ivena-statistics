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
