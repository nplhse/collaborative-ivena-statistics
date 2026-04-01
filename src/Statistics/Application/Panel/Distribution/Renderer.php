<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

final class Renderer
{
    /**
     * @param array{
     *     labels: list<string>,
     *     series: list<array{name: string, values: list<int>, percentages: list<float>}>,
     *     table: list<array{dimensionLabel: string, groupLabel: string|null, value: int, percent: float, isTotal: bool}>
     * } $distribution
     *
     * @return array{
     *     chart: array<string, mixed>,
     *     table: list<array{dimensionLabel: string, groupLabel: string|null, value: int, percent: float, isTotal: bool}>
     * }
     */
    public function render(array $distribution, string $viewMode): array
    {
        $usePercent = \in_array($viewMode, ['percent', 'percent_of_total'], true);
        $chartSeries = [];
        foreach ($distribution['series'] as $item) {
            $chartSeries[] = [
                'name' => $item['name'],
                'data' => $usePercent ? $item['percentages'] : $item['values'],
            ];
        }

        $stacked = \in_array($viewMode, ['stacked', 'percent'], true);

        return [
            'chart' => [
                'chart' => [
                    'type' => 'bar',
                    'stacked' => $stacked,
                    'stackType' => 'percent' === $viewMode ? '100%' : null,
                    'height' => 320,
                    'toolbar' => ['show' => false],
                ],
                'xaxis' => ['categories' => $distribution['labels']],
                'dataLabels' => ['enabled' => false],
                'yaxis' => $usePercent ? ['max' => 100] : [],
                'series' => $chartSeries,
            ],
            'table' => $distribution['table'],
        ];
    }
}
