<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisChartDataReducer;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
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

        $this->reducer = new GenericAnalysisChartDataReducer(new DimensionRegistry(), $translator);
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
        self::assertSame(6, $reduced->counts[5]);
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
        $result = new NormalizedAnalysisResult(
            title: 'Test',
            primaryDimensionLabel: 'Month',
            seriesDimensionLabel: 'Urgency',
            grandTotal: 280,
            rows: [],
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
        self::assertSame(30, $reduced->series[5]['data'][0]);
        self::assertSame(0, $reduced->series[5]['data'][1]);
    }

    public function testLimitsCategoricalBucketsWithSeries(): void
    {
        $query = $this->query('hospital', 'urgency');
        $labels = ['H1', 'H2', 'H3', 'H4', 'H5', 'H6'];
        $series = [
            ['name' => 'U1', 'data' => [10, 1, 1, 1, 1, 1]],
            ['name' => 'U2', 'data' => [1, 1, 1, 1, 1, 50]],
        ];
        $result = new NormalizedAnalysisResult(
            title: 'Test',
            primaryDimensionLabel: 'Hospital',
            seriesDimensionLabel: 'Urgency',
            grandTotal: 70,
            rows: [],
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
        $result = new NormalizedAnalysisResult(
            title: 'Test',
            primaryDimensionLabel: 'Gender',
            seriesDimensionLabel: null,
            grandTotal: 8,
            rows: [
                new \App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow('1', 'Male', 5, 62.5, 100.0),
                new \App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow('2', 'Female', 3, 37.5, 100.0),
            ],
            chartData: ['labels' => ['Male', 'Female']],
        );

        $reduced = $this->reducer->reduce($query, $result);

        self::assertSame([5, 3], $reduced->counts);
    }

    public function testReturnsEmptyLabelsWhenChartDataInvalid(): void
    {
        $query = $this->query('month');
        $result = new NormalizedAnalysisResult(
            title: 'Test',
            primaryDimensionLabel: 'Month',
            seriesDimensionLabel: null,
            grandTotal: 0,
            rows: [],
            chartData: ['labels' => null],
        );

        $reduced = $this->reducer->reduce($query, $result);

        self::assertSame([], $reduced->labels);
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
     * @param list<string> $labels
     * @param list<int>    $values
     */
    private function normalizedResult(array $labels, array $values): NormalizedAnalysisResult
    {
        return new NormalizedAnalysisResult(
            title: 'Test',
            primaryDimensionLabel: 'Dim',
            seriesDimensionLabel: null,
            grandTotal: array_sum($values),
            rows: [],
            chartData: [
                'labels' => $labels,
                'values' => $values,
            ],
        );
    }
}
