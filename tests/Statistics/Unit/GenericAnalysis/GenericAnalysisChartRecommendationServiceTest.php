<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow;
use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisChartRecommendationService;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GenericAnalysisChartRecommendationServiceTest extends TestCase
{
    private GenericAnalysisChartRecommendationService $service;

    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $this->service = new GenericAnalysisChartRecommendationService(
            new DimensionRegistry(),
            $translator,
        );
    }

    public function testEmptyDataHasNoChart(): void
    {
        $query = $this->query('month');
        $result = $this->normalizedResult(grandTotal: 0, chartData: ['labels' => [], 'values' => []]);

        $recommendation = $this->service->recommend($query, $result);

        self::assertFalse($recommendation->hasChart);
        self::assertSame('empty_data', $recommendation->reason);
        self::assertSame([], $recommendation->allowedChartTypes);
    }

    public function testAllocationsByMonthRecommendsBarAndLine(): void
    {
        $query = $this->query('month');
        $result = $this->normalizedResult(
            chartData: [
                'type' => 'bar',
                'labels' => ['Jan', 'Feb'],
                'values' => [10, 5],
            ],
        );

        $recommendation = $this->service->recommend($query, $result);

        self::assertTrue($recommendation->hasChart);
        self::assertSame(GenericAnalysisChartType::Bar, $recommendation->defaultChartType);
        self::assertContains(GenericAnalysisChartType::Bar, $recommendation->allowedChartTypes);
        self::assertContains(GenericAnalysisChartType::Line, $recommendation->allowedChartTypes);
        self::assertSame('temporal_no_series', $recommendation->reason);
    }

    public function testUrgencyByMonthRecommendsStackedBar(): void
    {
        $query = $this->query('month', 'urgency');
        $result = $this->normalizedResult(
            chartData: [
                'type' => 'bar',
                'labels' => ['Jan', 'Feb'],
                'series' => [
                    ['name' => 'U1', 'data' => [5, 3]],
                    ['name' => 'U2', 'data' => [2, 1]],
                ],
            ],
            rows: [
                GenericAnalysisTestFixtures::enrichedRow('1', 'Jan', 5, 50.0, 62.5, '1', 'U1'),
                GenericAnalysisTestFixtures::enrichedRow('1', 'Jan', 3, 30.0, 37.5, '2', 'U2'),
            ],
        );

        $recommendation = $this->service->recommend($query, $result);

        self::assertTrue($recommendation->hasChart);
        self::assertSame(GenericAnalysisChartType::StackedBar, $recommendation->defaultChartType);
        self::assertContains(GenericAnalysisChartType::GroupedBar, $recommendation->allowedChartTypes);
        self::assertContains(GenericAnalysisChartType::PercentStackedBar, $recommendation->allowedChartTypes);
        self::assertSame('temporal_with_series', $recommendation->reason);
    }

    public function testGenderDistributionRecommendsBar(): void
    {
        $query = $this->query('gender');
        $result = $this->normalizedResult(
            chartData: [
                'type' => 'pie',
                'labels' => ['Male', 'Female'],
                'values' => [10, 8],
            ],
        );

        $recommendation = $this->service->recommend($query, $result);

        self::assertTrue($recommendation->hasChart);
        self::assertSame(GenericAnalysisChartType::Bar, $recommendation->defaultChartType);
        self::assertSame([], $recommendation->warnings);
    }

    public function testAgeDimensionHasNoApexChart(): void
    {
        $query = $this->query('age');
        $result = $this->normalizedResult(
            chartData: [
                'type' => 'histogram',
                'labels' => ['30'],
                'values' => [5],
            ],
        );

        $recommendation = $this->service->recommend($query, $result);

        self::assertFalse($recommendation->hasChart);
        self::assertSame(GenericAnalysisChartType::Table, $recommendation->defaultChartType);
        self::assertContains('stats.generic_analysis.chart.warning.numeric_dimension', $recommendation->warnings);
    }

    public function testManySeriesAddsWarning(): void
    {
        $series = [];
        $rows = [];
        for ($i = 1; $i <= 10; ++$i) {
            $series[] = ['name' => 'S'.$i, 'data' => [1]];
            $rows[] = GenericAnalysisTestFixtures::enrichedRow('1', 'Jan', 1, 10.0, 10.0, (string) $i, 'S'.$i);
        }

        $query = $this->query('month', 'urgency');
        $result = $this->normalizedResult(
            chartData: [
                'labels' => ['Jan'],
                'series' => $series,
            ],
            rows: $rows,
        );

        $recommendation = $this->service->recommend($query, $result);

        self::assertContains('stats.generic_analysis.chart.warning.many_series', $recommendation->warnings);
    }

    public function testManyBucketsAddsHorizontalBarForTemporal(): void
    {
        $labels = [];
        $values = [];
        for ($i = 1; $i <= 30; ++$i) {
            $labels[] = 'Bucket '.$i;
            $values[] = $i;
        }

        $recommendation = $this->service->recommend(
            $this->query('month'),
            $this->normalizedResult(chartData: ['labels' => $labels, 'values' => $values]),
        );

        self::assertContains(GenericAnalysisChartType::HorizontalBar, $recommendation->allowedChartTypes);
        self::assertContains('stats.generic_analysis.chart.warning.many_buckets', $recommendation->warnings);
    }

    public function testCategoricalWithSeriesRecommendation(): void
    {
        $recommendation = $this->service->recommend(
            $this->query('hospital', 'urgency'),
            $this->normalizedResult(
                chartData: [
                    'labels' => ['H1'],
                    'series' => [['name' => 'U1', 'data' => [5]]],
                ],
            ),
        );

        self::assertTrue($recommendation->hasChart);
        self::assertSame('categorical_with_series', $recommendation->reason);
        self::assertContains(GenericAnalysisChartType::StackedBar, $recommendation->allowedChartTypes);
        self::assertSame(GenericAnalysisChartType::StackedBar, $recommendation->defaultChartType);
    }

    public function testSeriesCountFromChartDataWhenRowsEmpty(): void
    {
        $recommendation = $this->service->recommend(
            $this->query('month', 'urgency'),
            $this->normalizedResult(
                chartData: [
                    'labels' => ['Jan'],
                    'series' => [['name' => 'U1', 'data' => [1]], ['name' => 'U2', 'data' => [2]]],
                ],
            ),
        );

        self::assertTrue($recommendation->hasChart);
    }

    public function testHourWithWeekdaySeriesRecommendsHeatmapPlaceholder(): void
    {
        $query = $this->query('hour', 'weekday');
        $result = $this->normalizedResult(
            chartData: [
                'labels' => ['0', '1'],
                'series' => [['name' => 'Monday', 'data' => [1, 2]]],
            ],
        );

        $recommendation = $this->service->recommend($query, $result);

        self::assertFalse($recommendation->hasChart);
        self::assertSame(GenericAnalysisChartType::Heatmap, $recommendation->defaultChartType);
        self::assertContains('stats.generic_analysis.chart.warning.heatmap_not_implemented', $recommendation->warnings);
    }

    private function query(string $primary, ?string $series = null): AnalysisQuery
    {
        return new AnalysisQuery(
            primaryDimensionKey: $primary,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            seriesDimensionKey: $series,
        );
    }

    /**
     * @param array<string, mixed>      $chartData
     * @param list<EnrichedAnalysisRow> $rows
     */
    private function normalizedResult(
        int $grandTotal = 15,
        array $chartData = [],
        array $rows = [],
    ): NormalizedAnalysisResult {
        return GenericAnalysisTestFixtures::normalizedResult(
            rows: $rows,
            grandTotal: $grandTotal,
            chartData: [] === $chartData ? ['labels' => [], 'values' => []] : $chartData,
        );
    }
}
