<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\UI\Http\Controller;

use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationMapChoroplethFeature;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationMapMarker;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationParticipationResult;

final readonly class HospitalPopulationMapPayloadFactory
{
    /**
     * @return array{
     *     markers: list<array{id: int, name: string, lat: float, lng: float, beds: int, careLevel: string|null, location: string, isParticipating: bool}>,
     *     choropleth: list<array{geoFeatureKey: string, landkreis: string, population: int, participants: int, coverage: float}>
     * }
     */
    public function create(HospitalPopulationParticipationResult $result): array
    {
        return [
            'markers' => array_map(
                static fn (HospitalPopulationMapMarker $marker): array => [
                    'id' => $marker->id,
                    'name' => $marker->name,
                    'lat' => $marker->latitude,
                    'lng' => $marker->longitude,
                    'beds' => $marker->beds,
                    'careLevel' => $marker->careLevel,
                    'location' => $marker->location,
                    'isParticipating' => $marker->isParticipating,
                ],
                $result->mapMarkers,
            ),
            'choropleth' => array_map(
                static fn (HospitalPopulationMapChoroplethFeature $feature): array => [
                    'geoFeatureKey' => $feature->geoFeatureKey,
                    'landkreis' => $feature->landkreis,
                    'population' => $feature->population,
                    'participants' => $feature->participants,
                    'coverage' => $feature->coverage,
                ],
                $result->mapChoropleth,
            ),
        ];
    }
}
