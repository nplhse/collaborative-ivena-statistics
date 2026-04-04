<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class Renderer
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array{
     *     labels: list<string>,
     *     series: list<array{name: string, values: list<int>, percentages: list<float>}>,
     *     table: list<array<string, mixed>>,
     *     dimensionKeys?: list<int>,
     *     groupKeys?: list<int>,
     *     statsByDimensionGroup?: array<int, array<int, array<string, mixed>>>,
     *     hospitalDistinctMatrix?: array<int, array<int, int>>,
     * } $distribution
     *
     * @return array{
     *     chart: array<string, mixed>,
     *     table: list<array<string, mixed>>,
     *     tableMode: string
     * }
     */
    public function render(
        array $distribution,
        string $viewMode,
        string $chartType,
        string $barBasis,
        ?DistributionNumericMetric $metric,
    ): array {
        $table = $distribution['table'];
        $tableMode = 'boxplot' === $chartType ? 'boxplot' : ('average' === $barBasis ? 'bar_average' : 'bar_counts');

        if ('boxplot' === $chartType) {
            return [
                'chart' => $this->buildBoxPlotChart($distribution, $metric),
                'table' => $table,
                'tableMode' => $tableMode,
            ];
        }

        $usePercent = 'counts' === $barBasis && \in_array($viewMode, ['percent', 'percent_of_total'], true);
        $chartSeries = [];

        if ('average' === $barBasis) {
            $statsMap = $distribution['statsByDimensionGroup'] ?? [];
            $dimensionKeys = $distribution['dimensionKeys'] ?? [];
            $groupKeys = $distribution['groupKeys'] ?? [];
            foreach ($groupKeys as $gi => $groupKey) {
                $name = $distribution['series'][$gi]['name'] ?? (string) $groupKey;
                $data = [];
                foreach ($dimensionKeys as $dimensionKey) {
                    $stats = $statsMap[$dimensionKey][$groupKey] ?? null;
                    $data[] = null !== $stats && $stats['n'] > 0 ? round((float) $stats['mean'], 2) : 0.0;
                }
                $chartSeries[] = ['name' => $name, 'data' => $data];
            }
        } else {
            foreach ($distribution['series'] as $item) {
                $chartSeries[] = [
                    'name' => $item['name'],
                    'data' => $usePercent ? $item['percentages'] : $item['values'],
                ];
            }
        }

        $stacked = 'counts' === $barBasis && \in_array($viewMode, ['stacked', 'percent'], true);

        $yaxis = [];
        if ($usePercent) {
            $yaxis['max'] = 100;
        }
        if ('average' === $barBasis) {
            $yaxis['title'] = ['text' => $this->translator->trans('statistics.distribution.bar_basis.average.yaxis_per_hospital')];
            $yaxis['decimalsInFloat'] = 1;
        }

        return [
            'chart' => [
                'chart' => [
                    'type' => 'bar',
                    'stacked' => $stacked,
                    'stackType' => 'counts' === $barBasis && 'percent' === $viewMode ? '100%' : null,
                    'height' => 320,
                    'toolbar' => ['show' => false],
                ],
                'xaxis' => ['categories' => $distribution['labels']],
                'dataLabels' => ['enabled' => false],
                'yaxis' => $yaxis,
                'series' => $chartSeries,
            ],
            'table' => $table,
            'tableMode' => $tableMode,
        ];
    }

    private function metricYAxisLabel(DistributionNumericMetric $metric): string
    {
        $key = 'statistics.distribution.metric.'.$metric->value.'.yaxis';

        return $this->translator->trans($key);
    }

    /**
     * @param array{
     *     labels: list<string>,
     *     series: list<array{name: string, values: list<int>, percentages: list<float>}>,
     *     table?: list<array<string, mixed>>,
     *     dimensionKeys?: list<int>,
     *     groupKeys?: list<int>,
     *     statsByDimensionGroup?: array<int, array<int, array<string, mixed>>>,
     *     hospitalDistinctMatrix?: array<int, array<int, int>>,
     * } $distribution
     *
     * @return array<string, mixed>
     */
    private function buildBoxPlotChart(array $distribution, ?DistributionNumericMetric $metric): array
    {
        $statsMap = $distribution['statsByDimensionGroup'] ?? null;
        $dimensionKeys = $distribution['dimensionKeys'] ?? null;
        $groupKeys = $distribution['groupKeys'] ?? null;
        $labels = $distribution['labels'];
        $seriesMeta = $distribution['series'];

        if (!\is_array($statsMap) || !\is_array($dimensionKeys) || !\is_array($groupKeys)) {
            return $this->emptyBoxPlotFallback($metric);
        }

        $boxSeries = [];
        foreach ($groupKeys as $gi => $groupKey) {
            $name = $seriesMeta[$gi]['name'] ?? (string) $groupKey;
            $data = [];
            foreach ($dimensionKeys as $di => $dimensionKey) {
                $stats = $statsMap[$dimensionKey][$groupKey] ?? null;
                if (!\is_array($stats) || ($stats['n'] ?? 0) <= 0) {
                    continue;
                }

                $data[] = [
                    'x' => $labels[$di],
                    'y' => [
                        (float) $stats['min'],
                        (float) $stats['q1'],
                        (float) $stats['median'],
                        (float) $stats['q3'],
                        (float) $stats['max'],
                    ],
                ];
            }

            if ([] !== $data) {
                $boxSeries[] = [
                    'name' => $name,
                    'type' => 'boxPlot',
                    'data' => $data,
                ];
            }
        }

        if ([] === $boxSeries) {
            return $this->emptyBoxPlotFallback($metric);
        }

        $yTitle = $metric instanceof DistributionNumericMetric
            ? $this->metricYAxisLabel($metric)
            : $this->translator->trans('statistics.distribution.boxplot.yaxis_generic');

        return [
            'chart' => [
                'type' => 'boxPlot',
                'height' => 320,
                'toolbar' => ['show' => false],
            ],
            'xaxis' => [
                'type' => 'category',
                'title' => ['text' => $this->translator->trans('statistics.distribution.table.category')],
            ],
            'yaxis' => [
                'title' => ['text' => $yTitle],
                'decimalsInFloat' => 1,
            ],
            'series' => $boxSeries,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBoxPlotFallback(?DistributionNumericMetric $metric): array
    {
        $yTitle = $metric instanceof DistributionNumericMetric
            ? $this->metricYAxisLabel($metric)
            : $this->translator->trans('statistics.distribution.boxplot.yaxis_generic');

        return [
            'chart' => [
                'type' => 'boxPlot',
                'height' => 320,
                'toolbar' => ['show' => false],
            ],
            'xaxis' => ['type' => 'category'],
            'yaxis' => [
                'title' => ['text' => $yTitle],
            ],
            'series' => [],
        ];
    }
}
