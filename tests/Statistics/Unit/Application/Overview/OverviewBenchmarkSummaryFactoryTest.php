<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Overview;

use App\Statistics\Application\Overview\OverviewBenchmarkSummaryFactory;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistribution;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistributionBucket;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkHeader;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkHeatmapData;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricFormat;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkReport;
use App\Statistics\Benchmarking\UI\Http\Controller\BenchmarkIndicationMixViewModelFactory;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class OverviewBenchmarkSummaryFactoryTest extends TestCase
{
    public function testBuildScorecardReturnsEmptyWhenInsufficientData(): void
    {
        $factory = $this->factory();

        self::assertSame([], $factory->buildScorecard($this->report(hasInsufficientData: true)));
    }

    public function testBuildScorecardMapsMetricStatusAndFormattedValues(): void
    {
        $factory = $this->factory();
        $report = $this->report(
            kpiMetrics: [
                new BenchmarkMetric(
                    BenchmarkMetricKey::WithPhysician,
                    15.5,
                    12.0,
                    3.5,
                    29.2,
                    1.15,
                    BenchmarkMetricFormat::Percent,
                ),
                new BenchmarkMetric(
                    BenchmarkMetricKey::MedianTransport,
                    42.3,
                    40.0,
                    2.3,
                    5.8,
                    0.85,
                    BenchmarkMetricFormat::Minutes,
                ),
                new BenchmarkMetric(
                    BenchmarkMetricKey::Resus,
                    4.0,
                    5.0,
                    -1.0,
                    -20.0,
                    0.8,
                    BenchmarkMetricFormat::Percent,
                ),
            ],
        );

        $items = $factory->buildScorecard($report);

        self::assertCount(3, $items);
        self::assertSame('with_physician', $items[0]->key);
        self::assertSame('15,5%', $items[0]->displayValue);
        self::assertSame('above', $items[0]->status);
        self::assertSame('42,3 min', $items[1]->displayValue);
        self::assertSame('below', $items[1]->status);
        self::assertSame('below', $items[2]->status);
    }

    public function testBuildScorecardSuppressesDeviationStatusWhenRatiosAreSuppressed(): void
    {
        $factory = $this->factory();
        $report = $this->report(
            kpiMetrics: [
                new BenchmarkMetric(
                    BenchmarkMetricKey::MedianAge,
                    72.0,
                    65.0,
                    7.0,
                    10.8,
                    1.2,
                    BenchmarkMetricFormat::Years,
                ),
            ],
            suppressRatios: true,
        );

        $items = $factory->buildScorecard($report);

        self::assertCount(1, $items);
        self::assertSame('within', $items[0]->status);
    }

    public function testBuildScorecardKpiCardsWrapsScorecardItems(): void
    {
        $factory = $this->factory();
        $report = $this->report(
            kpiMetrics: [
                new BenchmarkMetric(
                    BenchmarkMetricKey::WithPhysician,
                    10.0,
                    10.0,
                    0.0,
                    0.0,
                    1.0,
                    BenchmarkMetricFormat::Percent,
                ),
            ],
        );

        $cards = $factory->buildScorecardKpiCards($report);

        self::assertCount(1, $cards);
        self::assertSame('benchmark_with_physician', $cards[0]->key);
        self::assertSame('10,0%', $cards[0]->displayValue);
    }

    public function testBuildDeviationsReturnsEmptyWhenRatiosAreSuppressed(): void
    {
        $factory = $this->factory();

        $deviations = $factory->buildDeviations(
            Request::create('/statistics/'),
            $this->report(suppressRatios: true),
        );

        self::assertSame(['positive' => [], 'negative' => []], $deviations);
    }

    public function testBuildDeviationsSortsAndLimitsTopBuckets(): void
    {
        $factory = $this->factory();
        $report = $this->report(
            indicationMix: new BenchmarkDistribution(BenchmarkMetricKey::IndicationMix, [
                $this->bucket('101', 'Alpha', 1.25),
                $this->bucket('102', 'Beta', 1.15),
                $this->bucket('103', 'Gamma', 1.12),
                $this->bucket('104', 'Delta', 1.11),
                $this->bucket('201', 'Low A', 0.85),
                $this->bucket('202', 'Low B', 0.8),
                $this->bucket('203', 'Low C', 0.75),
                $this->bucket('204', 'Low D', 0.7),
            ]),
        );

        $deviations = $factory->buildDeviations(Request::create('/statistics/'), $report);

        self::assertCount(3, $deviations['positive']);
        self::assertCount(3, $deviations['negative']);
        self::assertSame('Alpha', $deviations['positive'][0]->label);
        self::assertSame('Beta', $deviations['positive'][1]->label);
        self::assertSame('Gamma', $deviations['positive'][2]->label);
        self::assertSame('Low D', $deviations['negative'][0]->label);
        self::assertSame('above', $deviations['positive'][0]->direction);
        self::assertSame('below', $deviations['negative'][0]->direction);
        self::assertSame('https://example.test/indication/101', $deviations['positive'][0]->url);
    }

    private function factory(): OverviewBenchmarkSummaryFactory
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $routeName, array $parameters = []): string => sprintf(
                'https://example.test/indication/%s',
                $parameters['indicationId'] ?? '0',
            ),
        );

        return new OverviewBenchmarkSummaryFactory(
            new BenchmarkIndicationMixViewModelFactory(
                new StatisticsNavigationUrlBuilder($router),
            ),
        );
    }

    /**
     * @param list<BenchmarkMetric> $kpiMetrics
     */
    private function report(
        array $kpiMetrics = [],
        bool $hasInsufficientData = false,
        bool $suppressRatios = false,
        ?BenchmarkDistribution $indicationMix = null,
    ): BenchmarkReport {
        $emptyDistribution = new BenchmarkDistribution(BenchmarkMetricKey::IndicationMix, []);

        return new BenchmarkReport(
            new BenchmarkHeader('Primary', 'Comparison', 'Primary period', 'Comparison period', 1000, 5000),
            [],
            $kpiMetrics,
            $indicationMix ?? $emptyDistribution,
            BenchmarkHeatmapData::empty(),
            BenchmarkHeatmapData::empty(),
            $emptyDistribution,
            $emptyDistribution,
            $emptyDistribution,
            $emptyDistribution,
            $emptyDistribution,
            $emptyDistribution,
            $emptyDistribution,
            $emptyDistribution,
            $emptyDistribution,
            $hasInsufficientData,
            $suppressRatios,
        );
    }

    private function bucket(string $key, string $label, float $ratio): BenchmarkDistributionBucket
    {
        return new BenchmarkDistributionBucket(
            $key,
            $label,
            100,
            100,
            10.0,
            8.0,
            $ratio,
        );
    }
}
