<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Http\Controller;

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
                'closedHours' => array_map(static fn (ClosureTimeBucket $row): float => round($row->closedMinutes / 60, 2), $timeSeries),
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
}
