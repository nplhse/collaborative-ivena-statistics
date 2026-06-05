<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisChartDataReducer;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GenericAnalysisChartDataReducerTest extends TestCase
{
    private GenericAnalysisChartDataReducer $reducer;

    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => match ($id) {
                'stats.generic_analysis.chart.remainder_bucket',
                'stats.generic_analysis.chart.remainder_series' => 'Other',
                default => $id,
            },
        );

        $this->reducer = new GenericAnalysisChartDataReducer(
            new DimensionRegistry(),
            new MetricRegistry(),
            $translator,
        );
    }

    public function testLimitsCategoricalPrimaryBucketsToTopFivePlusOther(): void
    {
        $query = $this->query('hospital');
        $labels = ['H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'H7'];
        $values = [50, 40, 30, 20, 10, 5, 1];
        $result = $this->normalizedResult($labels, $values);

        $reduced = $this->reducer->reduce($query, $result);

        self::assertTrue($reduced->limitedPrimaryBuckets);
        self::assertCount(6, $reduced->labels);
        self::assertSame('Other', $reduced->labels[5]);
        self::assertEquals(6, $reduced->counts[5]);
    }

    public function testDoesNotLimitTemporalPrimaryBuckets(): void
    {
        $query = $this->query('month');
        $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $values = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
        $result = $this->normalizedResult($labels, $values);

        $reduced = $this->reducer->reduce($query, $result);

        self::assertFalse($reduced->limitedPrimaryBuckets);
        self::assertCount(12, $reduced->labels);
    }

    public function testLimitsSeriesToTopFivePlusOther(): void
    {
        $query = $this->query('month', 'urgency');
        $series = [];
        for ($i = 1; $i <= 7; ++$i) {
            $series[] = ['name' => 'U'.$i, 'data' => [$i * 10, 0]];
        }
        $result = GenericAnalysisTestFixtures::normalizedResult(
            seriesDimensionLabel: 'Urgency',
            grandTotal: 280,
            chartData: [
                'labels' => ['Jan', 'Feb'],
                'series' => $series,
            ],
        );

        $reduced = $this->reducer->reduce($query, $result);

        self::assertTrue($reduced->limitedSeries);
        self::assertNotNull($reduced->series);
        self::assertCount(6, $reduced->series);
        self::assertSame('Other', $reduced->series[5]['name']);
        self::assertEquals(30, $reduced->series[5]['data'][0]);
        self::assertEquals(0, $reduced->series[5]['data'][1]);
    }

    public function testLimitsCategoricalBucketsWithSeries(): void
    {
        $query = $this->query('hospital', 'urgency');
        $labels = ['H1', 'H2', 'H3', 'H4', 'H5', 'H6'];
        $series = [
            ['name' => 'U1', 'data' => [10, 1, 1, 1, 1, 1]],
            ['name' => 'U2', 'data' => [1, 1, 1, 1, 1, 50]],
        ];
        $result = GenericAnalysisTestFixtures::normalizedResult(
            seriesDimensionLabel: 'Urgency',
            grandTotal: 70,
            chartData: ['labels' => $labels, 'series' => $series],
        );

        $reduced = $this->reducer->reduce($query, $result);

        self::assertTrue($reduced->limitedPrimaryBuckets);
        self::assertNotNull($reduced->series);
        self::assertCount(6, $reduced->labels);
        self::assertSame('Other', $reduced->labels[5]);
    }

    public function testExtractsCountsFromRowsWhenValuesMissing(): void
    {
        $query = $this->query('gender');
        $result = GenericAnalysisTestFixtures::normalizedResult(
            rows: [
                GenericAnalysisTestFixtures::enrichedRow('1', 'Male', 5, 62.5, 100.0),
                GenericAnalysisTestFixtures::enrichedRow('2', 'Female', 3, 37.5, 100.0),
            ],
            grandTotal: 8,
            chartData: ['labels' => ['Male', 'Female']],
        );

        $reduced = $this->reducer->reduce($query, $result);

        self::assertSame([5, 3], $reduced->counts);
    }

    public function testUsesVisualMetricKeyFromResult(): void
    {
        $query = $this->query('gender', metricKeys: ['count', 'percent_of_total'], visualMetricKey: 'percent_of_total');
        $result = GenericAnalysisTestFixtures::normalizedResult(
            rows: [
                GenericAnalysisTestFixtures::enrichedRow('1', 'Male', 5, 62.5, 100.0),
                GenericAnalysisTestFixtures::enrichedRow('2', 'Female', 3, 37.5, 100.0),
            ],
            grandTotal: 8,
            metricKeys: ['count', 'percent_of_total'],
            chartData: ['labels' => ['Male', 'Female']],
            visualMetricKey: 'percent_of_total',
        );

        $reduced = $this->reducer->reduce($query, $result);

        self::assertSame([62.5, 37.5], $reduced->counts);
    }

    public function testPreservesMinutesVisualMetricAsFloat(): void
    {
        $query = $this->query(
            'department',
            metricKeys: ['count', 'median_transport_time'],
            visualMetricKey: 'median_transport_time',
        );
        $result = GenericAnalysisTestFixtures::normalizedResult(
            rows: [
                GenericAnalysisTestFixtures::enrichedRow('1', 'Dept A', 10, extraMetrics: ['median_transport_time' => 18.6]),
                GenericAnalysisTestFixtures::enrichedRow('2', 'Dept B', 5, extraMetrics: ['median_transport_time' => 22.4]),
            ],
            grandTotal: 15,
            metricKeys: ['count', 'median_transport_time'],
            chartData: ['labels' => ['Dept A', 'Dept B']],
            visualMetricKey: 'median_transport_time',
        );

        $reduced = $this->reducer->reduce($query, $result);

        self::assertSame([18.6, 22.4], $reduced->counts);
    }

    public function testReturnsEmptyLabelsWhenChartDataInvalid(): void
    {
        $query = $this->query('month');
        $result = GenericAnalysisTestFixtures::normalizedResult(
            chartData: ['labels' => null],
        );

        $reduced = $this->reducer->reduce($query, $result);

        self::assertSame([], $reduced->labels);
    }

    /**
     * @param list<string> $metricKeys
     */
    private function query(
        string $primary,
        ?string $series = null,
        array $metricKeys = [],
        ?string $visualMetricKey = null,
    ): AnalysisQuery {
        return new AnalysisQuery(
            primaryDimensionKey: $primary,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            seriesDimensionKey: $series,
            metricKeys: $metricKeys,
            visualMetricKey: $visualMetricKey,
        );
    }

    /**
     * @param list<string> $labels
     * @param list<int>    $values
     */
    private function normalizedResult(array $labels, array $values): \App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult
    {
        return GenericAnalysisTestFixtures::normalizedResult(
            grandTotal: array_sum($values),
            chartData: [
                'labels' => $labels,
                'values' => $values,
            ],
        );
    }
}
