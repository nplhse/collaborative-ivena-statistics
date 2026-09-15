<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\GeographicSegment;

use App\Statistics\Application\IsochroneOriginMap\Dto\IsochroneOriginHeatmapView;
use App\Statistics\CaseFlow\Application\CaseFlowPrivacyPolicy;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowDashboardResult;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMapFeature;

final readonly class GeographicSegmentCatalogFactory
{
    public function fromDashboardResult(CaseFlowDashboardResult $result): GeographicSegmentCatalog
    {
        return $this->fromMapFeatures(
            $result->mapFeatures,
            $result->isochrone,
            $result->singleHospital,
        );
    }

    /**
     * @param list<CaseFlowMapFeature> $mapFeatures
     */
    public function fromMapFeatures(
        array $mapFeatures,
        ?IsochroneOriginHeatmapView $isochrone,
        bool $singleHospital,
    ): GeographicSegmentCatalog {
        $options = $this->originOptions($mapFeatures);

        if ($singleHospital && $isochrone instanceof IsochroneOriginHeatmapView) {
            $options = [...$options, ...$this->travelOptions($isochrone)];
        }

        return new GeographicSegmentCatalog($options);
    }

    /**
     * @param list<CaseFlowMapFeature> $mapFeatures
     *
     * @return list<GeographicSegmentOption>
     */
    private function originOptions(array $mapFeatures): array
    {
        $options = [];
        foreach ($mapFeatures as $feature) {
            $suppressed = $feature->suppressed || $feature->caseCount < CaseFlowPrivacyPolicy::MIN_CASES_PER_CELL;
            $options[] = new GeographicSegmentOption(
                GeographicSegment::originArea($feature->dispatchAreaId),
                $feature->originName,
                false,
                $feature->caseCount,
                $suppressed,
                !$suppressed,
            );
        }

        return $options;
    }

    /**
     * @return list<GeographicSegmentOption>
     */
    private function travelOptions(IsochroneOriginHeatmapView $isochrone): array
    {
        $options = [];
        foreach ($isochrone->bands as $band) {
            $bandId = GeographicSegment::travelBandIdFromIsochroneMinutes($band->minutes);
            if (null === $bandId || $band->count <= 0) {
                continue;
            }

            $suppressed = $band->count < CaseFlowPrivacyPolicy::MIN_CASES_PER_CELL;
            $options[] = new GeographicSegmentOption(
                GeographicSegment::travelTimeBand($bandId),
                $band->label,
                false,
                $band->count,
                $suppressed,
                true,
            );
        }

        if ($isochrone->beyondMaxCount > 0) {
            $suppressed = $isochrone->beyondMaxCount < CaseFlowPrivacyPolicy::MIN_CASES_PER_CELL;
            $options[] = new GeographicSegmentOption(
                GeographicSegment::travelTimeBand('beyond_max'),
                'stats.case_flow.segment.travel.beyond_max',
                true,
                $isochrone->beyondMaxCount,
                $suppressed,
                true,
            );
        }

        if ($isochrone->unknownCount >= CaseFlowPrivacyPolicy::MIN_CASES_PER_CELL) {
            $options[] = new GeographicSegmentOption(
                GeographicSegment::travelTimeBand('unknown'),
                'statistics.distribution.transport_time_bucket.unknown',
                true,
                $isochrone->unknownCount,
                false,
                true,
            );
        }

        return $options;
    }
}
