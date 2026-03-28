<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

use App\Statistics\Domain\Model\DistributionPanelView;

/**
 * Maps {@see DistributionPanelView} to ApexCharts bar options.
 */
final class ApexChartsDistributionOptionsFactory
{
    /**
     * @return array<string, mixed>
     */
    public function build(
        DistributionPanelView $view,
        int $height = 320,
        ?DistributionGroupedChartMode $groupedChartMode = null,
    ): array {
        $series = [];
        foreach ($view->series as $s) {
            $series[] = [
                'name' => $s['name'],
                'data' => $s['values'],
            ];
        }

        $percentStacked = $view->grouped
            && DistributionGroupedChartMode::PercentStacked === $groupedChartMode;

        $clusteredAbsolute = $view->grouped
            && DistributionGroupedChartMode::AbsoluteGrouped === $groupedChartMode;

        $stacked = $percentStacked;

        $chart = [
            'type' => 'bar',
            'stacked' => $stacked,
            'height' => $height,
            'fontFamily' => 'inherit',
            'toolbar' => ['show' => false],
            'animations' => ['enabled' => false],
        ];

        if ($percentStacked) {
            $chart['stackType'] = '100%';
        }

        $columnWidth = '65%';
        if ($view->grouped) {
            $columnWidth = $clusteredAbsolute ? '72%' : '55%';
        }

        $yaxis = [
            'labels' => [
                'padding' => 4,
            ],
        ];

        if ($percentStacked) {
            $yaxis['max'] = 100;
        }

        return [
            'chart' => $chart,
            'plotOptions' => [
                'bar' => [
                    'horizontal' => false,
                    'columnWidth' => $columnWidth,
                    'borderRadius' => 2,
                ],
            ],
            'dataLabels' => ['enabled' => false],
            'grid' => [
                'strokeDashArray' => 4,
                'padding' => ['top' => -10, 'right' => 0, 'left' => -4, 'bottom' => -4],
            ],
            'xaxis' => [
                'categories' => $view->labels,
                'tooltip' => ['enabled' => false],
            ],
            'yaxis' => $yaxis,
            'tooltip' => [
                'theme' => 'dark',
                'shared' => true,
                'intersect' => false,
            ],
            'legend' => [
                'show' => true,
                'position' => 'bottom',
                'offsetY' => 12,
                'markers' => ['width' => 10, 'height' => 10, 'radius' => 100],
                'itemMargin' => ['horizontal' => 8, 'vertical' => 8],
            ],
            'series' => $series,
        ];
    }
}
