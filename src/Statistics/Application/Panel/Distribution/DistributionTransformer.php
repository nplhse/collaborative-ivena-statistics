<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

use App\Statistics\Application\Mapping\ValueMapper;

final class DistributionTransformer
{
    /**
     * @param list<array{dimension_key: int, group_key: int|null, value: int}> $rows
     *
     * @return array{
     *     labels: list<string>,
     *     series: list<array{name: string, values: list<int>, percentages: list<float>}>,
     *     table: list<array{dimensionLabel: string, groupLabel: string|null, value: int, percent: float, isTotal: bool}>
     * }
     */
    public function transform(array $rows, ValueMapper $dimensionMapper, ?ValueMapper $groupMapper): array
    {
        $dimensionValues = [];
        $groupValues = [];
        $matrix = [];
        foreach ($rows as $r) {
            $dimension = $r['dimension_key'];
            $group = $r['group_key'] ?? 0;
            $dimensionValues[$dimension] = true;
            $groupValues[$group] = true;
            $matrix[$group] ??= [];
            $matrix[$group][$dimension] = (int) (($matrix[$group][$dimension] ?? 0) + $r['value']);
        }

        $dimensionKeys = array_keys($dimensionValues);
        sort($dimensionKeys, SORT_NUMERIC);

        $groupKeys = array_keys($groupValues);
        sort($groupKeys, SORT_NUMERIC);

        $labels = [];
        $dimensionTotals = [];
        foreach ($dimensionKeys as $dimensionKey) {
            $labels[] = $dimensionMapper->label($dimensionKey);
            $sum = 0;
            foreach ($groupKeys as $groupKey) {
                $sum += $matrix[$groupKey][$dimensionKey] ?? 0;
            }
            $dimensionTotals[$dimensionKey] = $sum;
        }

        $overallTotal = array_sum($dimensionTotals);
        $overallTotalFloat = (float) $overallTotal;
        $series = [];
        foreach ($groupKeys as $groupKey) {
            $values = [];
            $percentages = [];
            foreach ($dimensionKeys as $dimensionKey) {
                $v = $matrix[$groupKey][$dimensionKey] ?? 0;
                $values[] = $v;
                if ($groupMapper instanceof ValueMapper) {
                    $total = (float) $dimensionTotals[$dimensionKey];
                    $percentages[] = $total > 0.0 ? round(((float) $v / $total) * 100.0, 2) : 0.0;
                    continue;
                }

                $percentages[] = $overallTotalFloat > 0.0 ? round(((float) $v / $overallTotalFloat) * 100.0, 2) : 0.0;
            }

            $series[] = [
                'name' => $groupMapper instanceof ValueMapper ? $groupMapper->label($groupKey) : 'Gesamt',
                'values' => $values,
                'percentages' => $percentages,
            ];
        }

        return [
            'labels' => $labels,
            'series' => $series,
            'table' => $this->buildTable($dimensionKeys, $groupKeys, $matrix, $dimensionTotals, $dimensionMapper, $groupMapper),
        ];
    }

    /**
     * @param list<int>                   $dimensionKeys
     * @param list<int>                   $groupKeys
     * @param array<int, array<int, int>> $matrix
     * @param array<int, int>             $dimensionTotals
     *
     * @return list<array{dimensionLabel: string, groupLabel: string|null, value: int, percent: float, isTotal: bool}>
     */
    private function buildTable(
        array $dimensionKeys,
        array $groupKeys,
        array $matrix,
        array $dimensionTotals,
        ValueMapper $dimensionMapper,
        ?ValueMapper $groupMapper,
    ): array {
        $rows = [];
        foreach ($dimensionKeys as $dimensionKey) {
            foreach ($groupKeys as $groupKey) {
                $value = $matrix[$groupKey][$dimensionKey] ?? 0;
                $grouped = $groupMapper instanceof ValueMapper;
                $total = $grouped ? (float) $dimensionTotals[$dimensionKey] : (float) array_sum($dimensionTotals);
                $groupLabel = null;
                if ($groupMapper instanceof ValueMapper) {
                    $groupLabel = $groupMapper->label($groupKey);
                }
                $rows[] = [
                    'dimensionLabel' => $dimensionMapper->label($dimensionKey),
                    'groupLabel' => $groupLabel,
                    'value' => $value,
                    'percent' => $total > 0.0 ? round(((float) $value / $total) * 100.0, 2) : 0.0,
                    'isTotal' => false,
                ];
            }
        }

        $rows[] = [
            'dimensionLabel' => 'Total',
            'groupLabel' => null,
            'value' => array_sum($dimensionTotals),
            'percent' => 100.0,
            'isTotal' => true,
        ];

        return $rows;
    }
}
