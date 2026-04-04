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
}
