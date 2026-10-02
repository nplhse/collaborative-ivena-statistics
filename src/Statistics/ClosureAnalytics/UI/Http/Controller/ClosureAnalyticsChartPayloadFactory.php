<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Http\Controller;

use App\Statistics\ClosureAnalytics\Application\ClosureDurationFormatter;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDurationDistributionGroup;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDurationLoad;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureHeatmapCell;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureTimeBucket;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ClosureAnalyticsChartPayloadFactory
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @param list<ClosureTimeBucket>  $timeSeries
     * @param list<ClosureHeatmapCell> $heatmap
     *
     * @return array<string, mixed>
     */
    public function dashboard(array $timeSeries, array $heatmap): array
    {
        $matrix = array_fill(0, 7, array_fill(0, 12, null));
        foreach ($heatmap as $cell) {
            $matrix[$cell->weekday - 1][$cell->twoHourSlot] = round($cell->closedMinutes / 60, 2);
        }

        return [
            'timeSeries' => [
                'labels' => array_map(static fn (ClosureTimeBucket $row): string => $row->key, $timeSeries),
                'closedHours' => array_map(
                    static fn (ClosureTimeBucket $row): int => (int) round($row->closedMinutes / 60),
                    $timeSeries,
                ),
                'seriesLabel' => $this->translator->trans('stats.closure.chart.duration', domain: 'statistics'),
                'tooltipSuffix' => $this->translator->trans('stats.closure.chart.hours', domain: 'statistics'),
            ],
            'heatmap' => [
                'rowLabels' => array_map(
                    fn (int $day): string => $this->translator->trans('stats.closure.heatmap.weekday.'.$day, domain: 'statistics'),
                    range(1, 7),
                ),
                'columnLabels' => array_map(
                    static fn (int $slot): string => sprintf('%02d–%02d', $slot * 2, ($slot + 1) * 2),
                    range(0, 11),
                ),
                'matrix' => $matrix,
                'durationLabel' => $this->translator->trans('stats.closure.chart.hours', domain: 'statistics'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function durationLoad(ClosureDurationLoad $load): array
    {
        return [
            'axisLabel' => $this->translator->trans('stats.closure.duration_load.axis_minutes', domain: 'statistics'),
            'boxName' => $this->translator->trans('stats.closure.duration_load.median', domain: 'statistics'),
            'pointName' => $this->translator->trans('stats.closure.duration_load.individual_values', domain: 'statistics'),
            'outlierName' => $this->translator->trans('stats.closure.duration_load.outliers', domain: 'statistics'),
            'tooltipLabels' => [
                'count' => $this->translator->trans('stats.closure.duration_load.column_count', domain: 'statistics'),
                'median' => $this->translator->trans('stats.closure.duration_load.column_median', domain: 'statistics'),
                'q1' => $this->translator->trans('stats.closure.duration_load.tooltip_q1', domain: 'statistics'),
                'q3' => $this->translator->trans('stats.closure.duration_load.tooltip_q3', domain: 'statistics'),
                'minimum' => $this->translator->trans('stats.closure.duration_load.tooltip_min', domain: 'statistics'),
                'maximum' => $this->translator->trans('stats.closure.duration_load.tooltip_max', domain: 'statistics'),
            ],
            'shares' => [
                $this->share('none', 'stats.closure.duration_load.share_none', $load->noneSeconds, $load),
                $this->share('single', 'stats.closure.duration_load.share_single', $load->singleDepartmentSeconds, $load),
                $this->share('multiple', 'stats.closure.duration_load.share_multiple', $load->multipleDepartmentsSeconds, $load),
            ],
            'specialities' => $this->distributionGroups($load->specialities),
            'reasons' => $this->distributionGroups($load->reasons),
        ];
    }

    /**
     * @return array{key: string, name: string, seconds: int, duration: string, percent: float, percentLabel: string}
     */
    private function share(string $key, string $label, int $seconds, ClosureDurationLoad $load): array
    {
        $percent = $load->sharePercent($seconds);

        return [
            'key' => $key,
            'name' => $this->translator->trans($label, domain: 'statistics'),
            'seconds' => $seconds,
            'duration' => ClosureDurationFormatter::humanize(ClosureDurationFormatter::minutesFromSeconds($seconds)),
            'percent' => $percent,
            'percentLabel' => number_format($percent, 1, ',', '.').'%',
        ];
    }

    /**
     * @param list<ClosureDurationDistributionGroup> $groups
     *
     * @return list<array<string, mixed>>
     */
    private function distributionGroups(array $groups): array
    {
        return array_map(function (ClosureDurationDistributionGroup $group): array {
            $label = $this->translator->trans('stats.closure.duration_load.group_label', [
                'name' => $group->label,
                'count' => $group->count,
            ], 'statistics');

            return [
                'label' => $label,
                'mode' => $group->showBox ? 'box' : 'points',
                'box' => $group->showBox ? [
                    $this->minutes($group->whiskerLowSeconds ?? $group->minimumSeconds),
                    $this->minutes($group->lowerQuartileSeconds),
                    $this->minutes($group->medianSeconds),
                    $this->minutes($group->upperQuartileSeconds),
                    $this->minutes($group->whiskerHighSeconds ?? $group->maximumSeconds),
                ] : null,
                'outliers' => array_map($this->minutes(...), $group->outlierSeconds),
                'points' => $group->showBox ? [] : array_map($this->minutes(...), $group->valueSeconds),
                'tooltip' => [
                    'count' => (string) $group->count,
                    'median' => $this->humanize($group->medianSeconds),
                    'q1' => $this->humanize($group->lowerQuartileSeconds),
                    'q3' => $this->humanize($group->upperQuartileSeconds),
                    'minimum' => $this->humanize($group->minimumSeconds),
                    'maximum' => $this->humanize($group->maximumSeconds),
                ],
            ];
        }, $groups);
    }

    private function minutes(int|float $seconds): float
    {
        return round((float) $seconds / 60.0, 4);
    }

    private function humanize(int|float $seconds): string
    {
        return ClosureDurationFormatter::humanize(ClosureDurationFormatter::minutesFromSeconds($seconds));
    }
}
