<?php

declare(strict_types=1);

namespace App\Statistics\GeographicMap\Application;

use App\Statistics\Application\IsochroneOriginMap\Dto\IsochroneOriginHeatmapView;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMapFeature;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMode;
use App\Statistics\GeographicMap\Application\DTO\GeographicHospitalPin;
use App\Statistics\GeographicMap\Application\DTO\GeographicMapLayer;

final readonly class GeographicMapPayloadBuilder
{
    /**
     * @param list<CaseFlowMapFeature>             $mapFeatures
     * @param list<GeographicHospitalPin>          $destinationHospitals
     * @param array{type: string, id: string}|null $selectedSegment
     *
     * @return array<string, mixed>
     */
    public function build(
        CaseFlowMode $mode,
        array $mapFeatures,
        array $destinationHospitals,
        ?IsochroneOriginHeatmapView $isochrone,
        bool $singleHospital,
        bool $dispatchAreaScope = false,
        ?int $selectedDispatchAreaId = null,
        int $omittedInsideDestinationHospitals = 0,
        int $omittedOutsideDestinationHospitals = 0,
        ?array $selectedSegment = null,
        bool $segmentSelectionEnabled = false,
    ): array {
        $featurePayload = array_map(
            static fn (CaseFlowMapFeature $feature): array => [
                'geoKey' => $feature->geoFeatureKey,
                'dispatchAreaId' => $feature->dispatchAreaId,
                'originName' => $feature->originName,
                'caseCount' => $feature->caseCount,
                'sharePercent' => $feature->sharePercent,
                'suppressed' => $feature->suppressed,
            ],
            $mapFeatures,
        );

        $hospitalPins = array_map(
            static fn (GeographicHospitalPin $pin): array => $pin->toMapPayload(),
            $destinationHospitals,
        );

        $isochronePayload = $isochrone?->mapPayload();
        $layers = $this->layers($mode, $singleHospital, $isochrone instanceof IsochroneOriginHeatmapView);
        $compactLayers = $this->compactLayers($mode, $singleHospital, $isochrone instanceof IsochroneOriginHeatmapView, $dispatchAreaScope);
        $expandedLayers = $layers;

        $payload = [
            'analysisLevel' => CaseFlowMode::HospitalOrigin === $mode ? 'hospital' : 'regional',
            'layers' => array_map(static fn (GeographicMapLayer $layer): string => $layer->value, $layers),
            'compactLayers' => array_map(static fn (GeographicMapLayer $layer): string => $layer->value, $compactLayers),
            'expandedLayers' => array_map(static fn (GeographicMapLayer $layer): string => $layer->value, $expandedLayers),
            'mapFeatures' => $featurePayload,
            'destinationHospitals' => $hospitalPins,
            'selectedDispatchAreaId' => $selectedDispatchAreaId,
            'omittedInsideDestinationHospitals' => $omittedInsideDestinationHospitals,
            'omittedOutsideDestinationHospitals' => $omittedOutsideDestinationHospitals,
            'selectedSegment' => $selectedSegment,
            'segmentSelectionEnabled' => $segmentSelectionEnabled,
        ];

        if ($isochrone instanceof IsochroneOriginHeatmapView && \is_array($isochronePayload)) {
            $payload['hospital'] = $isochronePayload['hospital'];
            $payload['isochrone'] = [
                'bands' => $isochronePayload['bands'],
                'unknownCount' => $isochrone->unknownCount,
                'beyondMaxCount' => $isochrone->beyondMaxCount,
            ];
            $payload['bands'] = $isochronePayload['bands'];
        }

        return $payload;
    }

    /**
     * @return list<GeographicMapLayer>
     */
    private function layers(CaseFlowMode $mode, bool $singleHospital, bool $hasIsochrone): array
    {
        if (CaseFlowMode::HospitalOrigin === $mode) {
            $layers = [GeographicMapLayer::OriginChoropleth];
            if ($singleHospital) {
                $layers[] = GeographicMapLayer::HospitalPin;
            }
            if ($hasIsochrone) {
                $layers[] = GeographicMapLayer::IsochroneBands;
            }

            return $layers;
        }

        return [
            GeographicMapLayer::OriginChoropleth,
            GeographicMapLayer::DestinationHospitals,
        ];
    }

    /**
     * @return list<GeographicMapLayer>
     */
    private function compactLayers(
        CaseFlowMode $mode,
        bool $singleHospital,
        bool $hasIsochrone,
        bool $dispatchAreaScope,
    ): array {
        if ($dispatchAreaScope) {
            return [
                GeographicMapLayer::OriginChoropleth,
                GeographicMapLayer::DestinationHospitals,
            ];
        }

        if (CaseFlowMode::HospitalOrigin === $mode && $hasIsochrone && $singleHospital) {
            return [
                GeographicMapLayer::OriginChoropleth,
                GeographicMapLayer::IsochroneBands,
                GeographicMapLayer::HospitalPin,
            ];
        }

        return [GeographicMapLayer::OriginChoropleth];
    }
}
