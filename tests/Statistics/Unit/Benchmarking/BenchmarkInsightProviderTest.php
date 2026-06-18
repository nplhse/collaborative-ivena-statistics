<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Benchmarking;

use App\Statistics\Benchmarking\Application\BenchmarkInsightProvider;
use App\Statistics\Benchmarking\Application\BenchmarkMetricBuilder;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkInsightDirection;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkInsightSeverity;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkAggregationResult;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkSideCounts;
use PHPUnit\Framework\TestCase;

final class BenchmarkInsightProviderTest extends TestCase
{
    private BenchmarkInsightProvider $provider;

    private BenchmarkMetricBuilder $metricBuilder;

    protected function setUp(): void
    {
        $this->provider = new BenchmarkInsightProvider();
        $this->metricBuilder = new BenchmarkMetricBuilder();
    }

    public function testReturnsEmptyListWhenSampleSizeTooSmall(): void
    {
        $aggregation = new BenchmarkAggregationResult(
            BenchmarkSideCounts::empty(),
            BenchmarkSideCounts::empty(),
            [],
        );

        self::assertSame([], $this->provider->build($aggregation, []));
    }

    public function testBuildsAboveInsightForHighPhysicianRate(): void
    {
        $aggregation = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                500, 400, 50, 30, 20, 15, 10, 0, 0, 5, 200, 150, 150,
                100, 90, 80, 260, 210, 0, 70.0, 50.0, 51.0,
            ),
            new BenchmarkSideCounts(
                5000, 1000, 200, 100, 80, 60, 40, 0, 0, 20, 1500, 2000, 1500,
                800, 700, 500, 2600, 2100, 0, 65.0, 45.0, 46.0,
            ),
            [],
        );

        $insights = $this->provider->build($aggregation, $this->metricBuilder->buildKpiMetrics($aggregation));

        self::assertNotEmpty($insights);
        self::assertSame('physician', $insights[0]->id);
        self::assertSame(BenchmarkInsightDirection::Above, $insights[0]->direction);
        self::assertSame(BenchmarkInsightSeverity::Critical, $insights[0]->severity);
    }

    public function testExcludesNeutralPhysicianInsightWhenBalanced(): void
    {
        $aggregation = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                500, 200, 20, 10, 5, 4, 3, 0, 0, 2, 100, 80, 80,
                50, 45, 40, 250, 200, 0, 60.0, 40.0, 41.0,
            ),
            new BenchmarkSideCounts(
                5000, 2100, 200, 100, 80, 60, 40, 0, 0, 20, 1000, 2000, 2000,
                500, 450, 400, 2500, 2000, 0, 60.0, 40.0, 41.0,
            ),
            [],
        );

        $insights = $this->provider->build($aggregation, $this->metricBuilder->buildKpiMetrics($aggregation));
        $ids = array_map(static fn (\App\Statistics\Benchmarking\Application\DTO\BenchmarkInsight $insight): string => $insight->id, $insights);

        self::assertNotContains('physician_neutral', $ids);
    }

    public function testReturnsEmptyListWhenOnlyNeutralInsightsExist(): void
    {
        $aggregation = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                500, 200, 20, 10, 5, 4, 3, 0, 0, 2, 100, 80, 80,
                250, 250, 0, 250, 200, 0, 60.0, 40.0, 41.0,
            ),
            new BenchmarkSideCounts(
                5000, 2100, 200, 100, 80, 60, 40, 0, 0, 20, 1000, 2000, 2000,
                2500, 2500, 0, 2500, 2000, 0, 60.0, 40.0, 41.0,
            ),
            [],
        );

        self::assertSame([], $this->provider->build($aggregation, $this->metricBuilder->buildKpiMetrics($aggregation)));
    }

    public function testBuildsBelowInsightForLowPhysicianRate(): void
    {
        $aggregation = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                500, 50, 20, 10, 5, 4, 3, 0, 0, 2, 100, 80, 80,
                50, 45, 40, 250, 200, 0, 60.0, 40.0, 41.0,
            ),
            new BenchmarkSideCounts(
                5000, 2500, 200, 100, 80, 60, 40, 0, 0, 20, 1000, 2000, 2000,
                500, 450, 400, 2500, 2000, 0, 60.0, 40.0, 41.0,
            ),
            [],
        );

        $insights = $this->provider->build($aggregation, $this->metricBuilder->buildKpiMetrics($aggregation));
        $ids = array_map(static fn (\App\Statistics\Benchmarking\Application\DTO\BenchmarkInsight $insight): string => $insight->id, $insights);

        self::assertContains('physician_low', $ids);
    }

    public function testBuildsTransportAndAgeInsightsFromKpiMetrics(): void
    {
        $aggregation = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                500, 200, 20, 10, 5, 4, 3, 0, 0, 2, 100, 80, 80,
                50, 45, 40, 250, 200, 0, 85.0, 72.0, 73.0,
            ),
            new BenchmarkSideCounts(
                5000, 2100, 200, 100, 80, 60, 40, 0, 0, 20, 1000, 2000, 2000,
                500, 450, 400, 2500, 2000, 0, 60.0, 45.0, 46.0,
            ),
            [],
        );

        $insights = $this->provider->build($aggregation, $this->metricBuilder->buildKpiMetrics($aggregation));
        $ids = array_map(static fn (\App\Statistics\Benchmarking\Application\DTO\BenchmarkInsight $insight): string => $insight->id, $insights);

        self::assertContains('age_old', $ids);
        self::assertContains('transport_time_long', $ids);
    }

    public function testBuildsYoungerAgeAndShorterTransportInsights(): void
    {
        $aggregation = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                500, 200, 20, 10, 5, 4, 3, 0, 0, 2, 100, 80, 80,
                50, 45, 40, 250, 200, 0, 45.0, 30.0, 31.0,
            ),
            new BenchmarkSideCounts(
                5000, 2100, 200, 100, 80, 60, 40, 0, 0, 20, 1000, 2000, 2000,
                500, 450, 400, 2500, 2000, 0, 60.0, 45.0, 46.0,
            ),
            [],
        );

        $insights = $this->provider->build($aggregation, $this->metricBuilder->buildKpiMetrics($aggregation));
        $ids = array_map(static fn (\App\Statistics\Benchmarking\Application\DTO\BenchmarkInsight $insight): string => $insight->id, $insights);

        self::assertContains('age_young', $ids);
    }

    public function testBuildsShorterTransportInsight(): void
    {
        $aggregation = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                500, 210, 20, 10, 5, 4, 3, 0, 0, 2, 100, 80, 80,
                50, 45, 40, 250, 200, 0, 58.0, 20.0, 40.0,
            ),
            new BenchmarkSideCounts(
                5000, 2100, 200, 100, 80, 60, 40, 0, 0, 20, 1000, 2000, 2000,
                500, 450, 400, 2500, 2000, 0, 58.0, 40.0, 41.0,
            ),
            [],
        );

        $insights = $this->provider->build($aggregation, $this->metricBuilder->buildKpiMetrics($aggregation));
        $ids = array_map(static fn (\App\Statistics\Benchmarking\Application\DTO\BenchmarkInsight $insight): string => $insight->id, $insights);

        self::assertContains('transport_time_short', $ids);
    }

    public function testExcludesGenderBalanceNeutralInsight(): void
    {
        $aggregation = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                500, 200, 20, 10, 5, 4, 3, 0, 0, 2, 100, 80, 80,
                50, 45, 40, 250, 250, 0, 60.0, 40.0, 41.0,
            ),
            new BenchmarkSideCounts(
                5000, 2100, 200, 100, 80, 60, 40, 0, 0, 20, 1000, 2000, 2000,
                500, 450, 400, 2500, 2500, 0, 60.0, 40.0, 41.0,
            ),
            [],
        );

        $insights = $this->provider->build($aggregation, $this->metricBuilder->buildKpiMetrics($aggregation));
        $ids = array_map(static fn (\App\Statistics\Benchmarking\Application\DTO\BenchmarkInsight $insight): string => $insight->id, $insights);

        self::assertNotContains('gender_balance', $ids);
    }

    public function testLimitsInsightsToMaxVisible(): void
    {
        $aggregation = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                500, 450, 200, 150, 120, 100, 80, 0, 0, 60, 350, 100, 50,
                300, 280, 250, 260, 210, 0, 90.0, 80.0, 81.0,
            ),
            new BenchmarkSideCounts(
                5000, 1000, 200, 100, 80, 60, 40, 0, 0, 20, 1500, 2000, 1500,
                800, 700, 500, 2600, 2100, 0, 60.0, 40.0, 41.0,
            ),
            [],
        );

        $insights = $this->provider->build($aggregation, $this->metricBuilder->buildKpiMetrics($aggregation));

        self::assertLessThanOrEqual(4, \count($insights));
    }
}
