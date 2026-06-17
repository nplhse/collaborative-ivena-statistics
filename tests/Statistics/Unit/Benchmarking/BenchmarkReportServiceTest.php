<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Benchmarking;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Benchmarking\Application\BenchmarkHeatmapBuilder;
use App\Statistics\Benchmarking\Application\BenchmarkInsightProvider;
use App\Statistics\Benchmarking\Application\BenchmarkMetricBuilder;
use App\Statistics\Benchmarking\Application\BenchmarkReportService;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkCriteria;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistributionBucket;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkAggregationResult;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkDistributionRow;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkSideCounts;
use App\Tests\Statistics\Unit\Benchmarking\Stub\FixedBenchmarkAggregationProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BenchmarkReportServiceTest extends TestCase
{
    private BenchmarkReportService $service;

    protected function setUp(): void
    {
        $aggregation = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                500, 300, 40, 20, 50, 30, 10, 5, 3, 8, 200, 150, 150,
                100, 90, 80, 260, 210, 0, 65.0, 45.0, 46.0,
            ),
            new BenchmarkSideCounts(
                5000, 2500, 400, 200, 500, 300, 100, 50, 30, 80, 2000, 1500, 1500,
                1000, 900, 800, 2600, 2100, 0, 65.0, 45.0, 46.0,
            ),
            [
                new BenchmarkDistributionRow('transport_type', '2', null, 100, 1000),
                new BenchmarkDistributionRow('transport_type', '1', null, 400, 4000),
                new BenchmarkDistributionRow('transport_time', '50_60', null, 50, 500),
                new BenchmarkDistributionRow('transport_time', 'under_10', null, 25, 250),
            ],
        );

        $aggregationProvider = new FixedBenchmarkAggregationProvider($aggregation);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->service = new BenchmarkReportService(
            $aggregationProvider,
            new BenchmarkMetricBuilder(),
            new BenchmarkHeatmapBuilder($translator),
            new BenchmarkInsightProvider(),
        );
    }

    public function testWiresClinicalFeaturesAndTransportSectionsCorrectly(): void
    {
        $report = $this->service->build(new BenchmarkCriteria(
            StatisticsScopeCriteria::public(),
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            new StatisticsPeriodBounds(null),
            'Primary',
            'Comparison',
            '2024',
            '2024',
        ));

        self::assertSame(BenchmarkMetricKey::ClinicalFeatures, $report->clinicalFeatures->dimension);
        self::assertSame(BenchmarkMetricKey::TransportType, $report->transportType->dimension);
        self::assertSame(BenchmarkMetricKey::TransportTimes, $report->transportTimes->dimension);

        self::assertSame(
            ['with_physician', 'cpr', 'ventilated', 'shock', 'pregnant', 'work_accident', 'infectious'],
            $this->bucketKeys($report->clinicalFeatures->buckets),
        );
        self::assertSame(['1', '2'], $this->bucketKeys($report->transportType->buckets));
        self::assertSame(
            ['under_10', '10_20', '20_30', '30_40', '40_50', '50_60', 'over_60'],
            $this->bucketKeys($report->transportTimes->buckets),
        );

        self::assertSame('stats.indication.transport.ground', $report->transportType->buckets[0]->label);
        self::assertSame('stats.indication.transport.air', $report->transportType->buckets[1]->label);
        self::assertSame('statistics.distribution.dim.is_with_physician', $report->clinicalFeatures->buckets[0]->label);

        self::assertSame(300, $report->clinicalFeatures->buckets[0]->primaryCount);
        self::assertSame(400, $report->transportType->buckets[0]->primaryCount);
        self::assertSame(25, $report->transportTimes->buckets[0]->primaryCount);

        self::assertFalse($report->hasInsufficientData);
        self::assertFalse($report->suppressRatios);
    }

    public function testSuppressesRatiosWhenEitherSideHasFewerThanTwentyCases(): void
    {
        $aggregation = new BenchmarkAggregationResult(
            new BenchmarkSideCounts(
                15, 10, 2, 1, 1, 1, 0, 0, 0, 0, 5, 5, 5,
                5, 5, 5, 10, 8, 0, 65.0, 45.0, 46.0,
            ),
            new BenchmarkSideCounts(
                5000, 2500, 400, 200, 500, 300, 100, 50, 30, 80, 2000, 1500, 1500,
                1000, 900, 800, 2600, 2100, 0, 65.0, 45.0, 46.0,
            ),
            [],
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $service = new BenchmarkReportService(
            new FixedBenchmarkAggregationProvider($aggregation),
            new BenchmarkMetricBuilder(),
            new BenchmarkHeatmapBuilder($translator),
            new BenchmarkInsightProvider(),
        );

        $report = $service->build(new BenchmarkCriteria(
            StatisticsScopeCriteria::public(),
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            new StatisticsPeriodBounds(null),
            'Primary',
            'Comparison',
            '2024',
            '2024',
        ));

        self::assertTrue($report->suppressRatios);
    }

    /**
     * @param list<BenchmarkDistributionBucket> $buckets
     *
     * @return list<string>
     */
    private function bucketKeys(array $buckets): array
    {
        return array_map(
            static fn (BenchmarkDistributionBucket $bucket): string => $bucket->key,
            $buckets,
        );
    }
}
