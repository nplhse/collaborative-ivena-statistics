<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\AnalysisExplorer\Application\ExplorerLegacyAnalyticsViewMapper;
use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;
use App\Statistics\Application\Overview\Dto\OverviewPortalNavigationViewModel;

final readonly class OverviewPortalNavigationFactory
{
    /** @var list<string> */
    private const array EXPLORER_REMOVE_KEYS = [
        'report',
        'limit',
        'view',
        'chart',
        'dimension',
    ];

    public function __construct(
        private ExplorerLegacyAnalyticsViewMapper $legacyViewMapper,
    ) {
    }

    public function build(): OverviewPortalNavigationViewModel
    {
        return new OverviewPortalNavigationViewModel(
            timeSeries: [
                $this->explorerTarget(
                    'stats.nav.overview_kpi_to_analysis',
                    'allocations_by_month',
                ),
            ],
            heatmapDayTime: [
                $this->explorerTarget(
                    'stats.nav.overview_heatmap_daytime_to_analysis',
                    'allocations_by_hour',
                ),
            ],
            heatmapShift: [
                $this->explorerTarget(
                    'stats.nav.overview_heatmap_shift_to_analysis',
                    'allocations_by_weekday',
                ),
            ],
            ageGroups: [
                $this->explorerTarget(
                    'stats.nav.overview_age_groups_to_analysis',
                    'age_group_distribution',
                ),
            ],
        );
    }

    public function resourcesOverTimeTarget(): StatisticWidgetNavigationTarget
    {
        return $this->explorerTarget(
            'stats.nav.overview_resources_to_analysis',
            'allocations_by_month',
        );
    }

    public function clinicalFeaturesOverTimeTarget(): StatisticWidgetNavigationTarget
    {
        return $this->explorerTarget(
            'stats.nav.overview_indicators_to_analysis',
            'allocations_by_month',
        );
    }

    private function explorerTarget(string $labelKey, string $legacyViewKey): StatisticWidgetNavigationTarget
    {
        $slug = $this->legacyViewMapper->slugForLegacyViewKey($legacyViewKey);
        if (null === $slug) {
            return new StatisticWidgetNavigationTarget(
                $labelKey,
                'app_stats_analysis_library',
                [],
                self::EXPLORER_REMOVE_KEYS,
            );
        }

        return new StatisticWidgetNavigationTarget(
            $labelKey,
            'app_stats_analysis_explorer_view',
            ['view' => $slug],
            self::EXPLORER_REMOVE_KEYS,
        );
    }
}
