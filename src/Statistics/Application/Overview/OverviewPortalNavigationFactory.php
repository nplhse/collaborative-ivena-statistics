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
                    'all_time',
                ),
            ],
            heatmapHour: [
                $this->explorerTarget(
                    'stats.nav.overview_allocations_by_hour',
                    'allocations_by_hour',
                ),
            ],
            heatmapWeekday: [
                $this->explorerTarget(
                    'stats.nav.overview_allocations_by_weekday_short',
                    'allocations_by_weekday',
                ),
            ],
            ageGroups: [
                $this->explorerTarget(
                    'stats.nav.overview_age_groups_to_analysis',
                    'age_group_distribution',
                ),
            ],
            transportTime: [
                $this->explorerTarget(
                    'stats.nav.overview_transport_time_to_analysis',
                    'transport_time_bucket_distribution',
                ),
            ],
        );
    }

    public function resourcesOverTimeTarget(): StatisticWidgetNavigationTarget
    {
        return $this->explorerTarget(
            'stats.nav.overview_resources_to_analysis',
            'clinical_resources_comparison',
            'all',
        );
    }

    public function clinicalFeaturesOverTimeTarget(): StatisticWidgetNavigationTarget
    {
        return $this->explorerTarget(
            'stats.nav.overview_indicators_to_analysis',
            'clinical_features_comparison',
            'all',
        );
    }

    private function explorerTarget(
        string $labelKey,
        string $legacyViewKey,
        ?string $forcedPeriod = null,
    ): StatisticWidgetNavigationTarget {
        $slug = $this->legacyViewMapper->slugForLegacyViewKey($legacyViewKey);
        if (null === $slug) {
            return new StatisticWidgetNavigationTarget(
                $labelKey,
                'app_stats_analysis_library',
                [],
                self::EXPLORER_REMOVE_KEYS,
            );
        }

        $params = ['view' => $slug];
        if (null !== $forcedPeriod) {
            $params['period'] = $forcedPeriod;
        }

        return new StatisticWidgetNavigationTarget(
            $labelKey,
            'app_stats_analysis_explorer_view',
            $params,
            self::EXPLORER_REMOVE_KEYS,
        );
    }
}
