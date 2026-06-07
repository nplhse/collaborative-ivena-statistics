<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\DTO\WidgetPayload\DistributionWidgetPayload;
use App\Statistics\Application\DTO\WidgetPayload\WidgetPayloadNormalizer;
use App\Statistics\Infrastructure\Query\Overview\Dto\OverviewDashboardMetricsResult;

final readonly class ClinicalFeaturesProvider
{
    public function __construct(
        private ClinicalFeaturesQuery $clinicalFeaturesQuery,
        private WidgetPayloadNormalizer $widgetPayloadNormalizer,
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
                    ],
                )),
            ),
            new StatisticWidget(
                StatisticWidgetType::Distribution,
                'clinical_features_distribution',
                $this->widgetPayloadNormalizer->normalize(new DistributionWidgetPayload(
                    'stats.analysis.dimension.features',
                    $this->clinicalFeaturesQuery->fetchClinicalRows($metrics),
                    [
                        'testId' => 'stats-overview-features',
                    ],
                )),
            ),
        ];
    }
}
