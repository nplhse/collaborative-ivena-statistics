<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Benchmarking;

use App\Statistics\Benchmarking\Application\BenchmarkMetricBuilder;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricFormat;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkAggregationResult;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkDistributionRow;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkSideCounts;
use PHPUnit\Framework\TestCase;

final class BenchmarkMetricBuilderTest extends TestCase
{
    private BenchmarkMetricBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new BenchmarkMetricBuilder();
    }

    public function testBuildsRateMetricsWithRatio(): void
    {
        $result = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                200, 120, 40, 20, 10, 8, 6, 0, 0, 4, 80, 60, 60,
                50, 40, 30, 100, 80, 0, 65.0, 45.0, 46.0,
            ),
            new BenchmarkSideCounts(
                1000, 400, 100, 50, 40, 30, 20, 0, 0, 10, 300, 400, 300,
                200, 180, 120, 520, 420, 0, 60.0, 40.0, 41.0,
            ),
            [],
        );

        $metrics = $this->builder->buildKpiMetrics($result);
        $physician = $this->findMetric($metrics, BenchmarkMetricKey::WithPhysician);

        self::assertNotNull($physician);
        self::assertSame(BenchmarkMetricFormat::Percent, $physician->format);
        self::assertSame(60.0, $physician->primaryValue);
        self::assertSame(40.0, $physician->comparisonValue);
        self::assertSame(1.5, $physician->ratio);
    }

    public function testBuildsIndicationMixWithMinimumCounts(): void
    {
        $result = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                500, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
                0, 0, 0, 0, 0, 0, null, null, null,
            ),
            new BenchmarkSideCounts(
                5000, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
                0, 0, 0, 0, 0, 0, null, null, null,
            ),
            [
                new BenchmarkDistributionRow('indication', '10', 'STEMI', 50, 100),
                new BenchmarkDistributionRow('indication', '11', 'Too small', 5, 100),
            ],
        );

        $mix = $this->builder->buildIndicationMix($result);

        self::assertCount(1, $mix->buckets);
        self::assertSame('STEMI', $mix->buckets[0]->label);
    }

    public function testBuildsGenderDistributionWithoutUnknown(): void
    {
        $result = new BenchmarkAggregationResult(
            BenchmarkSideCounts::empty(),
            BenchmarkSideCounts::empty(),
            [
                new BenchmarkDistributionRow('gender', 'male', null, 60, 500),
                new BenchmarkDistributionRow('gender', 'female', null, 30, 400),
                new BenchmarkDistributionRow('gender', 'unknown', null, 10, 100),
            ],
        );

        $distribution = $this->builder->buildGenderDistribution($result);

        self::assertCount(2, $distribution->buckets);
        self::assertSame(['male', 'female'], array_map(static fn (\App\Statistics\Benchmarking\Application\DTO\BenchmarkDistributionBucket $bucket): string => $bucket->key, $distribution->buckets));
    }

    public function testBuildsResourcesDistributionWithCathlabAndResusOnly(): void
    {
        $result = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                100, 0, 20, 10, 0, 0, 0, 0, 0, 0, 0, 0, 0,
                0, 0, 0, 0, 0, 0, null, null, null,
            ),
            new BenchmarkSideCounts(
                1000, 0, 200, 50, 0, 0, 0, 0, 0, 0, 0, 0, 0,
                0, 0, 0, 0, 0, 0, null, null, null,
            ),
            [],
        );

        $distribution = $this->builder->buildResourcesDistribution($result);

        self::assertCount(2, $distribution->buckets);
        self::assertSame(['cathlab', 'resus'], array_map(static fn (\App\Statistics\Benchmarking\Application\DTO\BenchmarkDistributionBucket $bucket): string => $bucket->key, $distribution->buckets));
    }

    public function testBuildsClinicalFeaturesDistributionInOverviewOrder(): void
    {
        $result = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                100, 80, 0, 0, 10, 8, 6, 2, 1, 5, 0, 0, 0,
                0, 0, 0, 0, 0, 0, null, null, null,
            ),
            BenchmarkSideCounts::empty(),
            [],
        );

        $distribution = $this->builder->buildClinicalFeaturesDistribution($result);

        self::assertSame(
            ['with_physician', 'cpr', 'ventilated', 'shock', 'pregnant', 'work_accident', 'infectious'],
            array_map(static fn (\App\Statistics\Benchmarking\Application\DTO\BenchmarkDistributionBucket $bucket): string => $bucket->key, $distribution->buckets),
        );
    }

    public function testBuildsAgeGroupDistributionInAscendingOrderWithAllBuckets(): void
    {
        $result = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                100, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
                0, 0, 0, 0, 0, 0, null, null, null,
            ),
            BenchmarkSideCounts::empty(),
            [
                new BenchmarkDistributionRow('age_group', '60_69', null, 10, 0),
                new BenchmarkDistributionRow('age_group', '0_17', null, 5, 0),
            ],
        );

        $distribution = $this->builder->buildAgeGroupDistribution($result);

        self::assertSame(
            ['0_17', '18_29', '30_39', '40_49', '50_59', '60_69', '70_79', '80_89', '90_99'],
            array_map(static fn (\App\Statistics\Benchmarking\Application\DTO\BenchmarkDistributionBucket $bucket): string => $bucket->key, $distribution->buckets),
        );
        self::assertSame(5.0, $distribution->buckets[0]->primaryShare);
        self::assertSame(10.0, $distribution->buckets[5]->primaryShare);
        self::assertSame(0.0, $distribution->buckets[1]->primaryShare);
    }

    public function testBuildsTransportTimeDistributionInAscendingBucketOrder(): void
    {
        $result = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                100, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
                0, 0, 0, 0, 0, 0, null, null, null,
            ),
            BenchmarkSideCounts::empty(),
            [
                new BenchmarkDistributionRow('transport_time', '50_60', null, 15, 0),
                new BenchmarkDistributionRow('transport_time', 'under_10', null, 5, 0),
            ],
        );

        $distribution = $this->builder->buildTransportTimeDistribution($result);

        self::assertSame(
            ['under_10', '10_20', '20_30', '30_40', '40_50', '50_60', 'over_60'],
            array_map(static fn (\App\Statistics\Benchmarking\Application\DTO\BenchmarkDistributionBucket $bucket): string => $bucket->key, $distribution->buckets),
        );
        self::assertSame(5.0, $distribution->buckets[0]->primaryShare);
        self::assertSame(15.0, $distribution->buckets[5]->primaryShare);
    }

    public function testBuildsTransportTypeDistributionWithGroundFirst(): void
    {
        $result = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                100, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
                0, 0, 0, 0, 0, 0, null, null, null,
            ),
            BenchmarkSideCounts::empty(),
            [
                new BenchmarkDistributionRow('transport_type', '2', null, 20, 0),
                new BenchmarkDistributionRow('transport_type', '1', null, 80, 0),
            ],
        );

        $distribution = $this->builder->buildTransportTypeDistribution($result);

        self::assertSame(['1', '2'], array_map(static fn (\App\Statistics\Benchmarking\Application\DTO\BenchmarkDistributionBucket $bucket): string => $bucket->key, $distribution->buckets));
        self::assertSame('stats.indication.transport.ground', $distribution->buckets[0]->label);
        self::assertSame('stats.indication.transport.air', $distribution->buckets[1]->label);
        self::assertSame(80.0, $distribution->buckets[0]->primaryShare);
    }

    public function testBuildsUrgencyDayTimeAndShiftDistributions(): void
    {
        $result = new BenchmarkAggregationResult(
            BenchmarkSideCounts::empty(),
            BenchmarkSideCounts::empty(),
            [
                new BenchmarkDistributionRow('urgency', '1', null, 40, 400),
                new BenchmarkDistributionRow('day_time_bucket', '2', null, 10, 100),
                new BenchmarkDistributionRow('shift_bucket', '2', null, 20, 200),
            ],
        );

        $urgency = $this->builder->buildUrgencyDistribution($result);
        $dayTime = $this->builder->buildDayTimeBucketDistribution($result);
        $shift = $this->builder->buildShiftBucketDistribution($result);

        self::assertSame('1', $urgency->buckets[0]->key);
        self::assertSame('stats.benchmark.day_time.morning', $dayTime->buckets[0]->label);
        self::assertSame('stats.benchmark.shift.early', $shift->buckets[0]->label);
    }

    public function testMetricByKeyReturnsMatchingMetric(): void
    {
        $result = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                200, 120, 40, 20, 10, 8, 6, 0, 0, 4, 80, 60, 60,
                50, 40, 30, 100, 80, 0, 65.0, 45.0, 46.0,
            ),
            BenchmarkSideCounts::empty(),
            [],
        );

        $metrics = $this->builder->buildKpiMetrics($result);

        self::assertSame(BenchmarkMetricKey::WithPhysician, $this->builder->metricByKey($metrics, BenchmarkMetricKey::WithPhysician)?->key);
        self::assertNull($this->builder->metricByKey($metrics, BenchmarkMetricKey::IndicationMix));
    }

    /**
     * @param list<\App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric> $metrics
     */
    private function findMetric(array $metrics, BenchmarkMetricKey $key): ?\App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric
    {
        foreach ($metrics as $metric) {
            if ($metric->key === $key) {
                return $metric;
            }
        }

        return null;
    }
}
