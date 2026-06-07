<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\DTO;

final readonly class CaseFlowDashboardResult
{
    /**
     * @param list<CaseFlowInsight>              $insights
     * @param list<CaseFlowOriginSlice>          $originSlices
     * @param list<CaseFlowFlowMatrixRow>        $flowMatrix
     * @param list<CaseFlowDestinationPoolSlice> $destinationTierSlices
     * @param list<CaseFlowDestinationPoolSlice> $destinationLocationSlices
     * @param list<CaseFlowDestinationPoolSlice> $destinationSizeSlices
     * @param list<CaseFlowDistributionSlice>    $transportTimeDistribution
     * @param list<CaseFlowDistributionSlice>    $urgencyDistribution
     * @param list<CaseFlowMapFeature>           $mapFeatures
     */
    public function __construct(
        public CaseFlowMode $mode,
        public CaseFlowKpiSet $kpis,
        public array $insights,
        public array $originSlices,
        public array $flowMatrix,
        public array $destinationTierSlices,
        public array $destinationLocationSlices,
        public array $destinationSizeSlices,
        public ?CaseFlowStructureDistributionCard $destinationTierCard,
        public ?CaseFlowStructureDistributionCard $destinationLocationCard,
        public ?CaseFlowStructureDistributionCard $destinationSizeCard,
        public array $transportTimeDistribution,
        public array $urgencyDistribution,
        public array $mapFeatures,
        public ?float $baselineMedianTransportMinutes,
        public ?float $baselineFullTierPercent,
    ) {
    }
}
