<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow;
use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisChartDataReducer;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisChartSpecBuilder;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GenericAnalysisChartSpecBuilderTest extends TestCase
{
    private GenericAnalysisChartSpecBuilder $builder;

    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Other');

        $this->builder = new GenericAnalysisChartSpecBuilder(
            new GenericAnalysisChartDataReducer(new DimensionRegistry(), $translator),
        );
    }

    public function testBarSpecMapsValuesToCounts(): void
    {
        $query = $this->query('month');
        $result = new NormalizedAnalysisResult(
            title: 'By month',
            primaryDimensionLabel: 'Month',
            seriesDimensionLabel: null,
            grandTotal: 15,
            rows: [],
            chartData: [
                'type' => 'bar',
                'labels' => ['Jan', 'Feb'],
                'values' => [10, 5],
            ],
        );

        $spec = $this->builder->buildSpec(GenericAnalysisChartType::Bar, $query, $result);

        self::assertNotNull($spec);
        self::assertSame('bar', $spec['chartType']);
        self::assertSame(['Jan', 'Feb'], $spec['labels']);
        self::assertSame([10, 5], $spec['counts']);
    }

    public function testGroupedBarSpecSetsBarGrouped(): void
    {
        $query = $this->query('month', 'urgency');
        $result = new NormalizedAnalysisResult(
            title: 'Urgency by month',
            primaryDimensionLabel: 'Month',
            seriesDimensionLabel: 'Urgency',
            grandTotal: 8,
            rows: [],
            chartData: [
                'labels' => ['Jan'],
                'series' => [['name' => 'U1', 'data' => [5]]],
            ],
        );

        $spec = $this->builder->buildSpec(GenericAnalysisChartType::GroupedBar, $query, $result);

        self::assertNotNull($spec);
        self::assertTrue($spec['barGrouped']);
    }

    public function testPercentStackedUsesReducedCounts(): void
    {
        $query = $this->query('month', 'urgency');
        $result = new NormalizedAnalysisResult(
            title: 'Test',
            primaryDimensionLabel: 'Month',
            seriesDimensionLabel: 'Urgency',
            grandTotal: 8,
            rows: [
                new EnrichedAnalysisRow('1', 'Jan', 5, 62.5, 62.5, '1', 'U1'),
                new EnrichedAnalysisRow('1', 'Jan', 3, 37.5, 37.5, '2', 'U2'),
            ],
            chartData: [
                'labels' => ['Jan'],
                'series' => [
                    ['name' => 'U1', 'data' => [5]],
                    ['name' => 'U2', 'data' => [3]],
                ],
            ],
        );

        $spec = $this->builder->buildSpec(GenericAnalysisChartType::PercentStackedBar, $query, $result);

        self::assertNotNull($spec);
        self::assertTrue($spec['percentScale']);
        self::assertEqualsWithDelta(62.5, $spec['series'][0]['data'][0], 0.01);
        self::assertEqualsWithDelta(37.5, $spec['series'][1]['data'][0], 0.01);
    }

    public function testBuildsStackedLineAndHorizontalSpecs(): void
    {
        $query = $this->query('month', 'urgency');
        $result = new NormalizedAnalysisResult(
            title: 'Urgency by month',
            primaryDimensionLabel: 'Month',
            seriesDimensionLabel: 'Urgency',
            grandTotal: 8,
            rows: [],
            chartData: [
                'labels' => ['Jan'],
                'series' => [
                    ['name' => 'U1', 'data' => [5]],
                    ['name' => 'U2', 'data' => [3]],
                ],
            ],
        );

        $stacked = $this->builder->buildSpec(GenericAnalysisChartType::StackedBar, $query, $result);
        $line = $this->builder->buildSpec(GenericAnalysisChartType::Line, $query, $result);
        $horizontal = $this->builder->buildSpec(GenericAnalysisChartType::HorizontalBar, $query, $result);

        self::assertSame('bar', $stacked['chartType']);
        self::assertSame('line', $line['chartType']);
        self::assertTrue($horizontal['horizontal']);
    }

    public function testBuildSpecsForTypesSkipsUnsupported(): void
    {
        $query = $this->query('month');
        $result = new NormalizedAnalysisResult(
            title: 'Test',
            primaryDimensionLabel: 'Month',
            seriesDimensionLabel: null,
            grandTotal: 5,
            rows: [],
            chartData: ['labels' => ['Jan'], 'values' => [5]],
        );

        $specs = $this->builder->buildSpecsForTypes(
            [
                GenericAnalysisChartType::Bar,
                GenericAnalysisChartType::Table,
                GenericAnalysisChartType::Heatmap,
            ],
            $query,
            $result,
        );

        self::assertArrayHasKey('bar', $specs);
        self::assertArrayNotHasKey('table', $specs);
        self::assertArrayNotHasKey('heatmap', $specs);
    }

    public function testPercentStackedFallsBackToBarWithoutSeries(): void
    {
        $query = $this->query('month');
        $result = new NormalizedAnalysisResult(
            title: 'Test',
            primaryDimensionLabel: 'Month',
            seriesDimensionLabel: null,
            grandTotal: 5,
            rows: [],
            chartData: ['labels' => ['Jan'], 'values' => [5]],
        );

        $spec = $this->builder->buildSpec(GenericAnalysisChartType::PercentStackedBar, $query, $result);

        self::assertSame('bar', $spec['chartType']);
        self::assertArrayHasKey('counts', $spec);
    }

    public function testManyHospitalsAreReducedInChartSpec(): void
    {
        $query = $this->query('hospital');
        $labels = [];
        $values = [];
        for ($i = 1; $i <= 8; ++$i) {
            $labels[] = 'Hospital '.$i;
            $values[] = 100 - $i;
        }
        $result = new NormalizedAnalysisResult(
            title: 'By hospital',
            primaryDimensionLabel: 'Hospital',
            seriesDimensionLabel: null,
            grandTotal: array_sum($values),
            rows: [],
            chartData: ['labels' => $labels, 'values' => $values],
        );

        $spec = $this->builder->buildSpec(GenericAnalysisChartType::Bar, $query, $result);

        self::assertNotNull($spec);
        self::assertCount(6, $spec['labels']);
        self::assertSame('Other', $spec['labels'][5]);
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
}
