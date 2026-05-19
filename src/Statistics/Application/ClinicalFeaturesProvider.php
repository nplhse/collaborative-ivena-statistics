<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\DTO\WidgetPayload\DistributionWidgetPayload;
use App\Statistics\Application\DTO\WidgetPayload\WidgetPayloadNormalizer;
use App\Statistics\Infrastructure\Query\Overview\Dto\OverviewDashboardMetricsResult;

final readonly class ClinicalFeaturesProvider
{
    /** @var list<string> */
    private const array ANALYSIS_CROSS_NAV_REMOVE_KEYS = ['report', 'limit', 'view', 'chart'];

    public function __construct(
        private ClinicalFeaturesQuery $clinicalFeaturesQuery,
        private WidgetPayloadNormalizer $widgetPayloadNormalizer,
    ) {
    }

    /**
     * @return list<StatisticWidget>
     */
    public function build(StatisticsContext $context, OverviewDashboardMetricsResult $metrics): array
    {
        return [
            new StatisticWidget(
                StatisticWidgetType::Distribution,
                'clinical_resources_distribution',
                $this->widgetPayloadNormalizer->normalize(new DistributionWidgetPayload(
                    'stats.analysis.dimension.resources',
                    $this->clinicalFeaturesQuery->fetchResourceRows($context, $metrics),
                    [
                        'testId' => 'stats-overview-resources',
                        'actionTestId' => 'stats-cross-nav-overview-resources',
                    ],
                )),
                actions: [
                    new StatisticWidgetNavigationTarget(
                        'stats.nav.overview_indicators_to_analysis',
                        'app_stats_analysis',
                        [
                            'analysis' => 'allocations_by_month',
                            'dimension' => 'resources',
                        ],
                        self::ANALYSIS_CROSS_NAV_REMOVE_KEYS,
                    ),
                ],
            ),
            new StatisticWidget(
                StatisticWidgetType::Distribution,
                'clinical_features_distribution',
                $this->widgetPayloadNormalizer->normalize(new DistributionWidgetPayload(
                    'stats.analysis.dimension.features',
                    $this->clinicalFeaturesQuery->fetchClinicalRows($context, $metrics),
                    [
                        'testId' => 'stats-overview-features',
                        'actionTestId' => 'stats-cross-nav-overview-features',
                    ],
                )),
                actions: [
                    new StatisticWidgetNavigationTarget(
                        'stats.nav.overview_indicators_to_analysis',
                        'app_stats_analysis',
                        [
                            'analysis' => 'allocations_by_month',
                            'dimension' => 'features',
                        ],
                        self::ANALYSIS_CROSS_NAV_REMOVE_KEYS,
                    ),
                ],
            ),
        ];
    }
}
