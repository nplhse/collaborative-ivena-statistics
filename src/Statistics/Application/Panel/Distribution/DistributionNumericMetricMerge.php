<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

final class DistributionNumericMetricMerge
{
    /**
     * Bar chart „Durchschnitt“: mean = allocations / distinct hospitals per cell; total row uses global ratio.
     *
     * @param array{
     *     labels: list<string>,
     *     series: list<array{name: string, values: list<int>, percentages: list<float>}>,
     *     table: list<array{dimensionLabel: string, groupLabel: string|null, value: int, percent: float, isTotal: bool}>,
     *     dimensionKeys: list<int>,
     *     groupKeys: list<int>,
     *     hospitalDistinctMatrix: array<int, array<int, int>>
     * } $distribution
     * @param array{allocations: int, distinct_hospitals: int}|null $overallParticipation
     *
     * @return array{
     *     labels: list<string>,
     *     series: list<array{name: string, values: list<int>, percentages: list<float>}>,
     *     table: list<array<string, mixed>>,
     *     dimensionKeys: list<int>,
     *     groupKeys: list<int>,
     *     statsByDimensionGroup: array<int, array<int, array<string, mixed>>>,
     *     hospitalDistinctMatrix: array<int, array<int, int>>
     * }
     */
    public function mergeCasesPerHospitalBarAverage(array $distribution, ?array $overallParticipation): array
    {
        $dimensionKeys = $distribution['dimensionKeys'];
        $groupKeys = $distribution['groupKeys'];
        $hospitalDistinct = $distribution['hospitalDistinctMatrix'] ?? [];
        $table = $distribution['table'];
        $statsByDg = [];

        $newTable = [];
        $i = 0;
        foreach ($dimensionKeys as $dimensionKey) {
            foreach ($groupKeys as $groupKey) {
                $row = $table[$i];
                $value = $row['value'];
                $distinct = $hospitalDistinct[$groupKey][$dimensionKey] ?? 0;
                $cellStats = $this->casesPerHospitalCellStats($value, $distinct);
                $statsByDg[$dimensionKey][$groupKey] = $cellStats;
                $newTable[] = [
                    ...$row,
                    'metricMean' => $this->roundMeanOrNull($cellStats['mean'], $value, $distinct),
                    'metricN' => $value,
                ];
                ++$i;
            }
        }

        $totalRow = $table[$i];
        $totalMean = null;
        $totalN = 0;
        if (null !== $overallParticipation && $overallParticipation['distinct_hospitals'] > 0) {
            $totalMean = round(
                $overallParticipation['allocations'] / $overallParticipation['distinct_hospitals'],
                1,
            );
            $totalN = $overallParticipation['allocations'];
        }
        $newTable[] = [
            ...$totalRow,
            'metricMean' => $totalMean,
            'metricN' => $totalN,
        ];

        return [
            'labels' => $distribution['labels'],
            'series' => $distribution['series'],
            'table' => $newTable,
            'dimensionKeys' => $dimensionKeys,
            'groupKeys' => $groupKeys,
            'statsByDimensionGroup' => $statsByDg,
            'hospitalDistinctMatrix' => $hospitalDistinct,
        ];
    }

    /**
     * @return array{n: int, mean: float, min: int, q1: float, median: float, q3: float, max: int}
     */
    private function casesPerHospitalCellStats(int $value, int $distinct): array
    {
        $mean = 0.0;
        if ($value > 0 && $distinct > 0) {
            $mean = (float) $value / (float) $distinct;
        }

        return [
            'n' => $value,
            'mean' => $mean,
            'min' => 0,
            'q1' => 0.0,
            'median' => 0.0,
            'q3' => 0.0,
            'max' => 0,
        ];
    }

    private function roundMeanOrNull(float $mean, int $value, int $distinct): ?float
    {
        if ($value <= 0 || $distinct <= 0) {
            return null;
        }

        return round($mean, 1);
    }

    /**
     * @param list<array{
     *     dimension_key: int,
     *     group_key: int|null,
     *     n: int,
     *     mean: float,
     *     min: int,
     *     q1: float,
     *     median: float,
     *     q3: float,
     *     max: int
     * }> $statRows
     * @param array{
     *     labels: list<string>,
     *     series: list<array{name: string, values: list<int>, percentages: list<float>}>,
     *     table: list<array{dimensionLabel: string, groupLabel: string|null, value: int, percent: float, isTotal: bool}|array<string, mixed>>,
     *     dimensionKeys: list<int>,
     *     groupKeys: list<int>,
     *     hospitalDistinctMatrix?: array<int, array<int, int>>,
     *     statsByDimensionGroup?: array<int, array<int, array<string, mixed>>>,
     * } $distribution
     * @param array{n: int, mean: float, min: int, q1: float, median: float, q3: float, max: int}|null $overall
     *
     * @return array{
     *     labels: list<string>,
     *     series: list<array{name: string, values: list<int>, percentages: list<float>}>,
     *     table: list<array<string, mixed>>,
     *     dimensionKeys: list<int>,
     *     groupKeys: list<int>,
     *     statsByDimensionGroup: array<int, array<int, array<string, mixed>>>,
     *     hospitalDistinctMatrix?: array<int, array<int, int>>,
     * }
     */
    public function mergeBoxplotTable(array $distribution, array $statRows, ?array $overall): array
    {
        $statsByDg = $this->statsMap($statRows);

        $dimensionKeys = $distribution['dimensionKeys'];
        $groupKeys = $distribution['groupKeys'];
        $table = $distribution['table'];
        $newTable = [];
        $i = 0;
        foreach ($dimensionKeys as $dimensionKey) {
            foreach ($groupKeys as $groupKey) {
                $row = $table[$i];
                $stats = $statsByDg[$dimensionKey][$groupKey] ?? null;
                $newTable[] = [
                    ...$row,
                    ...$this->metricCells($stats),
                ];
                ++$i;
            }
        }

        $totalRow = $table[$i];
        $newTable[] = [
            ...$totalRow,
            ...$this->metricCells($overall),
        ];

        $out = [
            'labels' => $distribution['labels'],
            'series' => $distribution['series'],
            'table' => $newTable,
            'dimensionKeys' => $dimensionKeys,
            'groupKeys' => $groupKeys,
            'statsByDimensionGroup' => $statsByDg,
        ];
        if (isset($distribution['hospitalDistinctMatrix'])) {
            $out['hospitalDistinctMatrix'] = $distribution['hospitalDistinctMatrix'];
        }

        return $out;
    }

    /**
     * @param list<array{dimension_key: int, group_key: int|null, n: int, mean: float, min: int, q1: float, median: float, q3: float, max: int}> $statRows
     *
     * @return array<int, array<int, array{n: int, mean: float, min: int, q1: float, median: float, q3: float, max: int}>>
     */
    private function statsMap(array $statRows): array
    {
        $map = [];
        foreach ($statRows as $r) {
            $d = $r['dimension_key'];
            $g = $r['group_key'] ?? 0;
            $map[$d][$g] = [
                'n' => $r['n'],
                'mean' => $r['mean'],
                'min' => $r['min'],
                'q1' => $r['q1'],
                'median' => $r['median'],
                'q3' => $r['q3'],
                'max' => $r['max'],
            ];
        }

        return $map;
    }

    /**
     * @param array{n: int, mean: float, min: int, q1: float, median: float, q3: float, max: int}|null $stats
     *
     * @return array<string, mixed>
     */
    private function metricCells(?array $stats): array
    {
        if (null === $stats || $stats['n'] <= 0) {
            return [
                'metricN' => 0,
                'metricMin' => null,
                'metricQ1' => null,
                'metricMedian' => null,
                'metricQ3' => null,
                'metricMax' => null,
                'metricMean' => null,
            ];
        }

        return [
            'metricN' => $stats['n'],
            'metricMin' => $stats['min'],
            'metricQ1' => round($stats['q1'], 1),
            'metricMedian' => round($stats['median'], 1),
            'metricQ3' => round($stats['q3'], 1),
            'metricMax' => $stats['max'],
            'metricMean' => round($stats['mean'], 1),
        ];
    }
}
