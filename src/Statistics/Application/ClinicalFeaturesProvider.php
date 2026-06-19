<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\DTO\WidgetPayload\DistributionWidgetPayload;
use App\Statistics\Application\DTO\WidgetPayload\WidgetPayloadNormalizer;
use App\Statistics\Application\Overview\OverviewPortalNavigationFactory;
use App\Statistics\Infrastructure\Query\Overview\Dto\OverviewDashboardMetricsResult;

final readonly class ClinicalFeaturesProvider
{
    public function __construct(
        private ClinicalFeaturesQuery $clinicalFeaturesQuery,
        private WidgetPayloadNormalizer $widgetPayloadNormalizer,
        private OverviewPortalNavigationFactory $overviewPortalNavigationFactory,
    ) {
    }

    /**
     * @return list<StatisticWidget>
     */
    public function build(StatisticsContext $_context, OverviewDashboardMetricsResult $metrics): array
    {
        return [
            new StatisticWidget(
                StatisticWidgetType::Distribution,
                'clinical_resources_distribution',
                $this->widgetPayloadNormalizer->normalize(new DistributionWidgetPayload(
                    'stats.analysis.dimension.resources',
                    $this->clinicalFeaturesQuery->fetchResourceRows($metrics),
                    [
                        'testId' => 'stats-overview-resources',
                        'actionTestId' => 'stats-cross-nav-overview-resources',
                    ],
                )),
                actions: [
                    $this->overviewPortalNavigationFactory->resourcesOverTimeTarget(),
                ],
            ),
            new StatisticWidget(
                StatisticWidgetType::Distribution,
                'clinical_features_distribution',
                $this->widgetPayloadNormalizer->normalize(new DistributionWidgetPayload(
                    'stats.analysis.dimension.features',
                    $this->clinicalFeaturesQuery->fetchClinicalRows($metrics),
                    [
                        'testId' => 'stats-overview-features',
                        'actionTestId' => 'stats-cross-nav-overview-indicators',
                    ],
                )),
                actions: [
                    $this->overviewPortalNavigationFactory->clinicalFeaturesOverTimeTarget(),
                ],
            ),
        ];
    }
}
