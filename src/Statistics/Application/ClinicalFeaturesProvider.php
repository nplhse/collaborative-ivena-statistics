<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;
use App\Statistics\Application\DTO\StatisticWidgetType;

final readonly class ClinicalFeaturesProvider
{
    /** @var list<string> */
    private const array ANALYSIS_CROSS_NAV_REMOVE_KEYS = ['report', 'limit', 'view', 'chart'];

    public function __construct(
        private ClinicalFeaturesQuery $clinicalFeaturesQuery,
    ) {
    }

    /**
     * @return list<StatisticWidget>
     */
    public function build(StatisticsContext $context): array
    {
        return [
            new StatisticWidget(
                StatisticWidgetType::Distribution,
                'clinical_resources_distribution',
                [
                    'titleTranslationKey' => 'stats.analysis.dimension.resources',
                    'rows' => $this->clinicalFeaturesQuery->fetchResourceRows($context),
                    'testId' => 'stats-overview-resources',
                    'actionTestId' => 'stats-cross-nav-overview-resources',
                ],
                actions: [
                    new StatisticWidgetNavigationTarget(
                        'stats.nav.overview_indicators_to_analysis',
                        'app_stats_analysis',
                        [
                            'analysis' => 'allocations_over_time',
                            'dimension' => 'resources',
                        ],
                        self::ANALYSIS_CROSS_NAV_REMOVE_KEYS,
                    ),
                ],
            ),
            new StatisticWidget(
                StatisticWidgetType::Distribution,
                'clinical_features_distribution',
                [
                    'titleTranslationKey' => 'stats.analysis.dimension.features',
                    'rows' => $this->clinicalFeaturesQuery->fetchClinicalRows($context),
                    'testId' => 'stats-overview-features',
                    'actionTestId' => 'stats-cross-nav-overview-features',
                ],
                actions: [
                    new StatisticWidgetNavigationTarget(
                        'stats.nav.overview_indicators_to_analysis',
                        'app_stats_analysis',
                        [
                            'analysis' => 'allocations_over_time',
                            'dimension' => 'features',
                        ],
                        self::ANALYSIS_CROSS_NAV_REMOVE_KEYS,
                    ),
                ],
            ),
        ];
    }
}
