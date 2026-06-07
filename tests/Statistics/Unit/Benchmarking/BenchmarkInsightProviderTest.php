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

    public function testReturnsEmptySummaryWhenSampleSizeTooSmall(): void
    {
        $aggregation = new BenchmarkAggregationResult(
            BenchmarkSideCounts::empty(),
            BenchmarkSideCounts::empty(),
            [],
        );

        $summary = $this->provider->build($aggregation, []);

        self::assertSame([], $summary->above);
        self::assertSame([], $summary->neutral);
        self::assertSame([], $summary->below);
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

        $kpiMetrics = $this->metricBuilder->buildKpiMetrics($aggregation);
        $summary = $this->provider->build($aggregation, $kpiMetrics);

        self::assertNotEmpty($summary->above);
        self::assertSame('physician', $summary->above[0]->id);
        self::assertSame(BenchmarkInsightDirection::Above, $summary->above[0]->direction);
        self::assertSame(BenchmarkInsightSeverity::Critical, $summary->above[0]->severity);
    }

    public function testBuildsNeutralPhysicianInsightWhenBalanced(): void
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

        $kpiMetrics = $this->metricBuilder->buildKpiMetrics($aggregation);
        $summary = $this->provider->build($aggregation, $kpiMetrics);

        $neutralIds = array_map(static fn (\App\Statistics\Benchmarking\Application\DTO\BenchmarkInsight $insight): string => $insight->id, $summary->neutral);
        self::assertContains('physician_neutral', $neutralIds);
    }
}
