<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Panel\Distribution;

use App\Statistics\Application\Panel\Distribution\DistributionNumericMetric;
use App\Statistics\Application\Panel\Distribution\Renderer;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RendererTest extends TestCase
{
    public function testRenderAbsoluteBarCountsUsesValuesAndTableModeBarCounts(): void
    {
        $result = $this->renderer()->render($this->distribution(), 'absolute', 'bar', 'counts', null);

        self::assertSame('bar_counts', $result['tableMode']);
        self::assertFalse($result['chart']['chart']['stacked']);
        self::assertNull($result['chart']['chart']['stackType']);
        self::assertSame([], $result['chart']['yaxis']);
        self::assertSame([10, 20], $result['chart']['series'][0]['data']);
        self::assertSame('bar', $result['chart']['chart']['type']);
    }

    public function testRenderPercentOfTotalBarCountsUsesPercentages(): void
    {
        $result = $this->renderer()->render($this->distribution(), 'percent_of_total', 'bar', 'counts', null);

        self::assertSame('bar_counts', $result['tableMode']);
        self::assertFalse($result['chart']['chart']['stacked']);
        self::assertNull($result['chart']['chart']['stackType']);
        self::assertSame(['max' => 100], $result['chart']['yaxis']);
        self::assertSame([25.0, 50.0], $result['chart']['series'][0]['data']);
    }

    public function testRenderStackedBarCountsUsesValuesWithStackedBars(): void
    {
        $result = $this->renderer()->render($this->distribution(), 'stacked', 'bar', 'counts', null);

        self::assertSame('bar_counts', $result['tableMode']);
        self::assertTrue($result['chart']['chart']['stacked']);
        self::assertNull($result['chart']['chart']['stackType']);
        self::assertSame([10, 20], $result['chart']['series'][0]['data']);
    }

    public function testRenderPercentBarCountsUsesHundredPercentStack(): void
    {
        $result = $this->renderer()->render($this->distribution(), 'percent', 'bar', 'counts', null);

        self::assertSame('bar_counts', $result['tableMode']);
        self::assertTrue($result['chart']['chart']['stacked']);
        self::assertSame('100%', $result['chart']['chart']['stackType']);
        self::assertSame(['max' => 100], $result['chart']['yaxis']);
        self::assertSame([25.0, 50.0], $result['chart']['series'][0]['data']);
    }

    public function testBarAverageUsesStatsMeanAndPerHospitalYAxisLabel(): void
    {
        $distribution = [
            'labels' => ['A', 'B'],
            'series' => [
                ['name' => 'Gesamt', 'values' => [10, 20], 'percentages' => [25.0, 50.0]],
            ],
            'table' => [],
            'dimensionKeys' => [1, 2],
            'groupKeys' => [0],
            'statsByDimensionGroup' => [
                1 => [0 => ['n' => 10, 'mean' => 2.5, 'min' => 0, 'q1' => 0.0, 'median' => 0.0, 'q3' => 0.0, 'max' => 0]],
                2 => [0 => ['n' => 8, 'mean' => 2.0, 'min' => 0, 'q1' => 0.0, 'median' => 0.0, 'q3' => 0.0, 'max' => 0]],
            ],
        ];

        $result = $this->renderer()->render($distribution, 'absolute', 'bar', 'average', null);

        self::assertSame('bar_average', $result['tableMode']);
        self::assertSame([2.5, 2.0], $result['chart']['series'][0]['data']);
        self::assertSame(
            'statistics.distribution.bar_basis.average.yaxis_per_hospital',
            $result['chart']['yaxis']['title']['text'],
        );
    }

    public function testBoxPlotSingleSeriesWhenNotGrouped(): void
    {
        $distribution = [
            'labels' => ['A', 'B'],
            'series' => [
                [
                    'name' => 'Gesamt',
                    'values' => [10, 20],
                    'percentages' => [25.0, 50.0],
                ],
            ],
            'table' => [],
            'dimensionKeys' => [1, 2],
            'groupKeys' => [0],
            'statsByDimensionGroup' => [
                1 => [
                    0 => [
                        'n' => 5,
                        'mean' => 40.0,
                        'min' => 30,
                        'q1' => 35.0,
                        'median' => 40.0,
                        'q3' => 45.0,
                        'max' => 50,
                    ],
                ],
                2 => [
                    0 => [
                        'n' => 3,
                        'mean' => 60.0,
                        'min' => 55,
                        'q1' => 57.0,
                        'median' => 60.0,
                        'q3' => 62.0,
                        'max' => 65,
                    ],
                ],
            ],
        ];

        $result = $this->renderer()->render($distribution, 'absolute', 'boxplot', 'counts', DistributionNumericMetric::Age);
        $chart = $result['chart'];

        self::assertSame('boxplot', $result['tableMode']);
        self::assertSame('boxPlot', $chart['chart']['type']);
        self::assertCount(1, $chart['series']);
        self::assertSame('boxPlot', $chart['series'][0]['type']);
        self::assertSame('Gesamt', $chart['series'][0]['name']);
        self::assertCount(2, $chart['series'][0]['data']);
        self::assertSame([30.0, 35.0, 40.0, 45.0, 50.0], $chart['series'][0]['data'][0]['y']);
    }

    public function testBoxPlotMultipleSeriesWhenGrouped(): void
    {
        $distribution = [
            'labels' => ['U1'],
            'series' => [
                ['name' => 'G10', 'values' => [10], 'percentages' => [50.0]],
                ['name' => 'G20', 'values' => [30], 'percentages' => [50.0]],
            ],
            'table' => [],
            'dimensionKeys' => [1],
            'groupKeys' => [10, 20],
            'statsByDimensionGroup' => [
                1 => [
                    10 => [
                        'n' => 2,
                        'mean' => 20.0,
                        'min' => 18,
                        'q1' => 19.0,
                        'median' => 20.0,
                        'q3' => 21.0,
                        'max' => 22,
                    ],
                    20 => [
                        'n' => 2,
                        'mean' => 70.0,
                        'min' => 68,
                        'q1' => 69.0,
                        'median' => 70.0,
                        'q3' => 71.0,
                        'max' => 72,
                    ],
                ],
            ],
        ];

        $chart = $this->renderer()->render($distribution, 'grouped', 'boxplot', 'counts', DistributionNumericMetric::Age)['chart'];

        self::assertCount(2, $chart['series']);
        self::assertSame('G10', $chart['series'][0]['name']);
        self::assertSame('G20', $chart['series'][1]['name']);
    }

    public function testBoxPlotFallsBackWhenStatsStructureMissing(): void
    {
        $distribution = [
            'labels' => ['A'],
            'series' => [['name' => 'Gesamt', 'values' => [1], 'percentages' => [100.0]]],
            'table' => [],
        ];

        $chart = $this->renderer()->render($distribution, 'absolute', 'boxplot', 'counts', DistributionNumericMetric::Age)['chart'];

        self::assertSame('boxPlot', $chart['chart']['type']);
        self::assertSame([], $chart['series']);
        self::assertSame(
            'statistics.distribution.metric.age.yaxis',
            $chart['yaxis']['title']['text'],
        );
    }

    public function testBoxPlotFallsBackWhenAllSeriesWouldBeEmpty(): void
    {
        $distribution = [
            'labels' => ['A', 'B'],
            'series' => [['name' => 'Gesamt', 'values' => [1, 1], 'percentages' => [50.0, 50.0]]],
            'table' => [],
            'dimensionKeys' => [1, 2],
            'groupKeys' => [0],
            'statsByDimensionGroup' => [
                1 => [0 => ['n' => 0, 'mean' => 0.0, 'min' => 0, 'q1' => 0.0, 'median' => 0.0, 'q3' => 0.0, 'max' => 0]],
                2 => [0 => ['n' => 0, 'mean' => 0.0, 'min' => 0, 'q1' => 0.0, 'median' => 0.0, 'q3' => 0.0, 'max' => 0]],
            ],
        ];

        $chart = $this->renderer()->render($distribution, 'absolute', 'boxplot', 'counts', null)['chart'];

        self::assertSame([], $chart['series']);
        self::assertSame(
            'statistics.distribution.boxplot.yaxis_generic',
            $chart['yaxis']['title']['text'],
        );
    }

    public function testBarAverageUsesZeroWhenStatsMapMissingEntries(): void
    {
        $distribution = [
            'labels' => ['A', 'B'],
            'series' => [
                ['name' => 'Gesamt', 'values' => [1, 2], 'percentages' => [33.33, 66.67]],
            ],
            'table' => [],
            'dimensionKeys' => [1, 2],
            'groupKeys' => [0],
            'statsByDimensionGroup' => [],
        ];

        $result = $this->renderer()->render($distribution, 'absolute', 'bar', 'average', null);

        self::assertSame([0.0, 0.0], $result['chart']['series'][0]['data']);
        self::assertSame(
            'statistics.distribution.bar_basis.average.yaxis_per_hospital',
            $result['chart']['yaxis']['title']['text'],
        );
    }

    private function renderer(): Renderer
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        return new Renderer($translator);
    }

    /**
     * @return array{
     *     labels: list<string>,
     *     series: list<array{name: string, values: list<int>, percentages: list<float>}>,
     *     table: list<array{dimensionLabel: string, groupLabel: string|null, value: int, percent: float, isTotal: bool}>
     * }
     */
    private function distribution(): array
    {
        return [
            'labels' => ['A', 'B'],
            'series' => [
                [
                    'name' => 'S1',
                    'values' => [10, 20],
                    'percentages' => [25.0, 50.0],
                ],
            ],
            'table' => [
                [
                    'dimensionLabel' => 'A',
                    'groupLabel' => null,
                    'value' => 10,
                    'percent' => 25.0,
                    'isTotal' => false,
                ],
            ],
        ];
    }
}
