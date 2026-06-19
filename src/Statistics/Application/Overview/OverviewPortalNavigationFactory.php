<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;
use App\Statistics\Application\Overview\Dto\OverviewPortalNavigationViewModel;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;

final readonly class OverviewPortalNavigationFactory
{
    /** @var list<string> */
    private const array ANALYTICS_REMOVE_KEYS = [
        'report',
        'limit',
        'view',
        'chart',
        'dimension',
        ...GenericAnalysisQueryKeys::REMOVE_CUSTOM,
    ];

    public function build(): OverviewPortalNavigationViewModel
    {
        return new OverviewPortalNavigationViewModel(
            timeSeries: [
                $this->analyticsTarget(
                    'stats.nav.overview_kpi_to_analysis',
                    'allocations_by_month',
                ),
            ],
            heatmapDayTime: [
                $this->analyticsTarget(
                    'stats.nav.overview_heatmap_daytime_to_analysis',
                    'hour_weekday_heatmap',
                ),
            ],
            heatmapShift: [
                $this->analyticsTarget(
                    'stats.nav.overview_heatmap_shift_to_analysis',
                    'allocations_by_weekday',
                ),
            ],
            ageGroups: [
                $this->analyticsTarget(
                    'stats.nav.overview_age_groups_to_analysis',
                    'age_group_distribution',
                ),
            ],
        );
    }

    public function resourcesOverTimeTarget(): StatisticWidgetNavigationTarget
    {
        return $this->analyticsTarget(
            'stats.nav.overview_resources_to_analysis',
            'clinical_rates_by_month',
        );
    }

    public function clinicalFeaturesOverTimeTarget(): StatisticWidgetNavigationTarget
    {
        return $this->analyticsTarget(
            'stats.nav.overview_indicators_to_analysis',
            'clinical_rates_by_month',
        );
    }

    private function analyticsTarget(string $labelKey, string $viewKey): StatisticWidgetNavigationTarget
    {
        return new StatisticWidgetNavigationTarget(
            $labelKey,
            'app_stats_analytics_view',
            ['viewKey' => $viewKey],
            self::ANALYTICS_REMOVE_KEYS,
        );
    }

    /**
     * @param list<string> $metrics
     */
    private function analyticsTargetWithMetrics(string $labelKey, array $metrics): StatisticWidgetNavigationTarget
    {
        return new StatisticWidgetNavigationTarget(
            $labelKey,
            'app_stats_analytics_view',
            [
                'viewKey' => 'allocations_by_month',
                GenericAnalysisQueryKeys::REF_PRESET => 'allocations_by_month',
                GenericAnalysisQueryKeys::PRIMARY => 'month',
                GenericAnalysisQueryKeys::SERIES => '',
                GenericAnalysisQueryKeys::METRICS => $metrics,
                GenericAnalysisQueryKeys::VISUAL_METRIC => 'count',
            ],
            self::ANALYTICS_REMOVE_KEYS,
        );
    }
}
