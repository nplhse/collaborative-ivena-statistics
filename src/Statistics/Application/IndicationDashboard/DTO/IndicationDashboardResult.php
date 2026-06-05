<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard\DTO;

use App\Statistics\Infrastructure\Query\IndicationDashboard\Dto\IndicationDashboardMetricsRow;

final readonly class IndicationDashboardResult
{
    /**
     * @param list<IndicationInsight>         $insights
     * @param list<IndicationDistributionRow> $resourcesDistribution
     * @param list<IndicationDistributionRow> $transportDistribution
     * @param list<IndicationDistributionRow> $transportTimeDistribution
     * @param list<IndicationDistributionRow> $clinicalFeatures
     * @param list<IndicationDistributionRow> $ageGroupDistribution
     */
    public function __construct(
        public IndicationDashboardHeader $header,
        public IndicationSummaryDeck $summaryDeck,
        public array $insights,
        public IndicationChartSeries $timeSeries,
        public IndicationHeatmapData $dayTimeHeatmap,
        public IndicationHeatmapData $shiftHeatmap,
        public array $resourcesDistribution,
        public array $transportDistribution,
        public array $transportTimeDistribution,
        public array $clinicalFeatures,
        public array $ageGroupDistribution,
        public ?float $medianAge,
        public IndicationDashboardMetricsRow $metrics,
    ) {
    }
}
