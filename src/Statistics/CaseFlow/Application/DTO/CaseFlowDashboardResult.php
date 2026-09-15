<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\DTO;

use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\IsochroneOriginMap\Dto\IsochroneOriginHeatmapView;
use App\Statistics\GeographicMap\Application\DTO\GeographicHospitalPin;

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
     * @param list<CaseFlowMapFeature>           $mapFeatures
     * @param list<GeographicHospitalPin>        $destinationHospitals
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
        public array $mapFeatures,
        public ?float $baselineMedianTransportMinutes,
        public ?float $baselineFullTierPercent,
        public array $destinationHospitals = [],
        public ?IsochroneOriginHeatmapView $isochrone = null,
        public bool $singleHospital = false,
        public StatisticsFilterScope $filterScope = StatisticsFilterScope::Public,
        public ?CaseFlowDispatchAreaFlow $dispatchAreaFlow = null,
        public ?CaseFlowDispatchAreaFlow $hospitalFlow = null,
        public ?int $selectedDispatchAreaId = null,
        public int $omittedInsideDestinationHospitals = 0,
        public int $omittedOutsideDestinationHospitals = 0,
    ) {
    }
}
