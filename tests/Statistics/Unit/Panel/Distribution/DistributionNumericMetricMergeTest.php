<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Panel\Distribution;

use App\Statistics\Application\Panel\Distribution\DistributionNumericMetricMerge;
use PHPUnit\Framework\TestCase;

final class DistributionNumericMetricMergeTest extends TestCase
{
    public function testMergeCasesPerHospitalBarAverageUsesValueOverDistinctAndOverallRatio(): void
    {
        $distribution = [
            'labels' => ['A', 'B'],
            'series' => [
                [
                    'name' => 'Gesamt',
                    'values' => [1, 2],
                    'percentages' => [50.0, 50.0],
                ],
            ],
            'dimensionKeys' => [1, 2],
            'groupKeys' => [0],
            'hospitalDistinctMatrix' => [
                0 => [
                    1 => 1,
                    2 => 0,
                ],
            ],
            'table' => [
                [
                    'dimensionLabel' => 'A',
                    'groupLabel' => null,
                    'value' => 1,
                    'percent' => 50.0,
                    'isTotal' => false,
                ],
                [
                    'dimensionLabel' => 'B',
                    'groupLabel' => null,
                    'value' => 2,
                    'percent' => 50.0,
                    'isTotal' => false,
                ],
                [
                    'dimensionLabel' => 'Total',
                    'groupLabel' => null,
                    'value' => 3,
                    'percent' => 100.0,
                    'isTotal' => true,
                ],
            ],
        ];

        $overall = ['allocations' => 10, 'distinct_hospitals' => 4];

        $merged = new DistributionNumericMetricMerge()->mergeCasesPerHospitalBarAverage($distribution, $overall);

        self::assertEqualsWithDelta(1.0, (float) $merged['table'][0]['metricMean'], 0.001);
        self::assertSame(1, $merged['table'][0]['metricN']);
        self::assertNull($merged['table'][1]['metricMean']);
        self::assertSame(2, $merged['table'][1]['metricN']);
        self::assertSame(2.5, $merged['table'][2]['metricMean']);
        self::assertSame(10, $merged['table'][2]['metricN']);

        self::assertEqualsWithDelta(1.0, (float) $merged['statsByDimensionGroup'][1][0]['mean'], 0.001);
        self::assertEqualsWithDelta(0.0, (float) $merged['statsByDimensionGroup'][2][0]['mean'], 0.001);
    }

    public function testMergeCasesPerHospitalBarAverageWithNullOverallLeavesTotalMetricEmpty(): void
    {
        $distribution = [
            'labels' => ['A'],
            'series' => [['name' => 'Gesamt', 'values' => [3], 'percentages' => [100.0]]],
            'dimensionKeys' => [1],
            'groupKeys' => [0],
            'hospitalDistinctMatrix' => [0 => [1 => 2]],
            'table' => [
                ['dimensionLabel' => 'A', 'groupLabel' => null, 'value' => 3, 'percent' => 100.0, 'isTotal' => false],
                ['dimensionLabel' => 'Total', 'groupLabel' => null, 'value' => 3, 'percent' => 100.0, 'isTotal' => true],
            ],
        ];

        $merged = new DistributionNumericMetricMerge()->mergeCasesPerHospitalBarAverage($distribution, null);

        self::assertSame(1.5, $merged['table'][0]['metricMean']);
        self::assertNull($merged['table'][1]['metricMean']);
        self::assertSame(0, $merged['table'][1]['metricN']);
    }

    public function testMergeCasesPerHospitalBarAverageMultipleGroups(): void
    {
        $distribution = [
            'labels' => ['A'],
            'series' => [
                ['name' => 'G10', 'values' => [2], 'percentages' => [100.0]],
                ['name' => 'G20', 'values' => [4], 'percentages' => [100.0]],
            ],
            'dimensionKeys' => [1],
            'groupKeys' => [10, 20],
            'hospitalDistinctMatrix' => [
                10 => [1 => 2],
                20 => [1 => 1],
            ],
            'table' => [
                ['dimensionLabel' => 'A', 'groupLabel' => 'G10', 'value' => 2, 'percent' => 33.33, 'isTotal' => false],
                ['dimensionLabel' => 'A', 'groupLabel' => 'G20', 'value' => 4, 'percent' => 66.67, 'isTotal' => false],
                ['dimensionLabel' => 'Total', 'groupLabel' => null, 'value' => 6, 'percent' => 100.0, 'isTotal' => true],
            ],
        ];

        $merged = new DistributionNumericMetricMerge()->mergeCasesPerHospitalBarAverage(
            $distribution,
            ['allocations' => 12, 'distinct_hospitals' => 3],
        );

        self::assertSame(1.0, $merged['table'][0]['metricMean']);
        self::assertSame(4.0, $merged['table'][1]['metricMean']);
        self::assertSame(4.0, $merged['table'][2]['metricMean']);
    }

    public function testMergeBoxplotTableFillsMetricCellsPerDimensionAndGroup(): void
    {
        $statRow = static fn (int $d, int $g, int $n): array => [
            'dimension_key' => $d,
            'group_key' => $g,
            'n' => $n,
            'mean' => 10.0,
            'min' => 1,
            'q1' => 2.0,
            'median' => 5.0,
            'q3' => 8.0,
            'max' => 9,
        ];

        $distribution = [
            'labels' => ['A', 'B'],
            'series' => [
                ['name' => 'G10', 'values' => [1, 1], 'percentages' => [50.0, 50.0]],
                ['name' => 'G20', 'values' => [1, 1], 'percentages' => [50.0, 50.0]],
            ],
            'dimensionKeys' => [1, 2],
            'groupKeys' => [10, 20],
            'table' => [
                ['dimensionLabel' => 'A', 'groupLabel' => 'G10', 'value' => 1, 'percent' => 50.0, 'isTotal' => false],
                ['dimensionLabel' => 'A', 'groupLabel' => 'G20', 'value' => 1, 'percent' => 50.0, 'isTotal' => false],
                ['dimensionLabel' => 'B', 'groupLabel' => 'G10', 'value' => 1, 'percent' => 50.0, 'isTotal' => false],
                ['dimensionLabel' => 'B', 'groupLabel' => 'G20', 'value' => 1, 'percent' => 50.0, 'isTotal' => false],
                ['dimensionLabel' => 'Total', 'groupLabel' => null, 'value' => 4, 'percent' => 100.0, 'isTotal' => true],
            ],
        ];

        $statRows = [
            $statRow(1, 10, 3),
            $statRow(1, 20, 0),
            $statRow(2, 10, 2),
            $statRow(2, 20, 4),
        ];

        $overall = [
            'n' => 9,
            'mean' => 11.0,
            'min' => 0,
            'q1' => 3.0,
            'median' => 6.0,
            'q3' => 9.0,
            'max' => 12,
        ];

        $merged = new DistributionNumericMetricMerge()->mergeBoxplotTable($distribution, $statRows, $overall);

        self::assertSame(3, $merged['table'][0]['metricN']);
        self::assertSame(0, $merged['table'][1]['metricN']);
        self::assertNull($merged['table'][1]['metricMean']);
        self::assertSame(9, $merged['table'][4]['metricN']);
        self::assertSame(11.0, $merged['table'][4]['metricMean']);
    }

    public function testMergeBoxplotTableWithNullOverallUsesEmptyMetricCellsOnTotal(): void
    {
        $distribution = [
            'labels' => ['A'],
            'series' => [['name' => 'Gesamt', 'values' => [1], 'percentages' => [100.0]]],
            'dimensionKeys' => [1],
            'groupKeys' => [0],
            'table' => [
                ['dimensionLabel' => 'A', 'groupLabel' => null, 'value' => 1, 'percent' => 100.0, 'isTotal' => false],
                ['dimensionLabel' => 'Total', 'groupLabel' => null, 'value' => 1, 'percent' => 100.0, 'isTotal' => true],
            ],
        ];

        $statRows = [[
            'dimension_key' => 1,
            'group_key' => null,
            'n' => 2,
            'mean' => 4.0,
            'min' => 1,
            'q1' => 2.0,
            'median' => 3.0,
            'q3' => 5.0,
            'max' => 6,
        ]];

        $merged = new DistributionNumericMetricMerge()->mergeBoxplotTable($distribution, $statRows, null);

        self::assertSame(2, $merged['table'][0]['metricN']);
        self::assertSame(0, $merged['table'][1]['metricN']);
        self::assertNull($merged['table'][1]['metricMean']);
    }
}
