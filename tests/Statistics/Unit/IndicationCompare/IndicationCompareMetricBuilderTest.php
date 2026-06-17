<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\IndicationCompare;

use App\Statistics\Application\IndicationCompare\IndicationCompareBenchmarkAdapter;
use App\Statistics\Application\IndicationCompare\IndicationCompareInsightEngine;
use App\Statistics\Benchmarking\Application\BenchmarkMetricBuilder;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use App\Statistics\Infrastructure\Query\IndicationCompare\Dto\IndicationCompareAggregationResult;
use App\Statistics\Infrastructure\Query\IndicationCompare\Dto\IndicationCompareSideCounts;
use PHPUnit\Framework\TestCase;

final class IndicationCompareMetricBuilderTest extends TestCase
{
    public function testBuildsKpiMetricsFromSideCounts(): void
    {
        $aggregation = new IndicationCompareAggregationResult(
            new IndicationCompareSideCounts(100, 50, 10, 5, 0, 0, 0, 0, 0, 0, 40, 20, 10, 0, 0, 0, 60, 40, 0, 0, 0, 70.0, 30.0),
            new IndicationCompareSideCounts(50, 10, 2, 1, 0, 0, 0, 0, 0, 0, 10, 5, 5, 0, 0, 0, 20, 30, 0, 0, 0, 55.0, 20.0),
        );

        $benchmark = new IndicationCompareBenchmarkAdapter()->toBenchmarkAggregation($aggregation);
        $metrics = new BenchmarkMetricBuilder()->buildIndicationCompareKpiMetrics($benchmark);
        $totalMetric = array_find($metrics, fn ($metric): bool => BenchmarkMetricKey::Total === $metric->key);

        self::assertNotNull($totalMetric);
        self::assertSame(100.0, $totalMetric->primaryValue);
        self::assertSame(50.0, $totalMetric->comparisonValue);
        self::assertSame(50.0, $totalMetric->absoluteDelta);
        self::assertSame(2.0, $totalMetric->ratio);
        self::assertSame(
            [
                BenchmarkMetricKey::Total,
                BenchmarkMetricKey::WithPhysician,
                BenchmarkMetricKey::MedianAge,
                BenchmarkMetricKey::Resus,
                BenchmarkMetricKey::Cathlab,
                BenchmarkMetricKey::Age80Plus,
                BenchmarkMetricKey::NightDaytime,
                BenchmarkMetricKey::Weekend,
                BenchmarkMetricKey::MedianTransport,
            ],
            array_map(static fn (\App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric $metric): BenchmarkMetricKey => $metric->key, $metrics),
        );
    }

    public function testInsightEngineRequiresMinimumCases(): void
    {
        $engine = new IndicationCompareInsightEngine();
        $lowA = IndicationCompareSideCounts::empty();
        $enoughB = new IndicationCompareSideCounts(50, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, null, null);

        self::assertSame([], $engine->build($lowA, $enoughB));
    }

    public function testBuildsUrgencyDistributionFromSideCounts(): void
    {
        $sideA = new IndicationCompareSideCounts(10, 0, 0, 0, 0, 0, 0, 0, 0, 0, 6, 3, 1, 0, 0, 0, 0, 0, 0, 0, 0, null, null);
        $sideB = new IndicationCompareSideCounts(8, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2, 2, 4, 0, 0, 0, 0, 0, 0, 0, 0, null, null);

        $distribution = new IndicationCompareBenchmarkAdapter()->buildUrgencyDistribution($sideA, $sideB);

        self::assertCount(3, $distribution->buckets);
        self::assertSame('1', $distribution->buckets[0]->key);
        self::assertSame(60.0, $distribution->buckets[0]->primaryShare);
        self::assertSame(25.0, $distribution->buckets[0]->comparisonShare);
        self::assertSame('stats.benchmark.urgency.outpatient', $distribution->buckets[2]->label);
    }
}
