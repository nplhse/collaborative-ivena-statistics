<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Infrastructure\Geocoding;

final readonly class HospitalPopulationGeocodingLookup
{
    /**
     * @param array<string, array{latitude: float, longitude: float}> $postalCodes
     * @param array<string, array{latitude: float, longitude: float}> $cities
     * @param array<string, array{latitude: float, longitude: float}> $dispatchAreas
     */
    public function __construct(
        private array $postalCodes,
        private array $cities,
        private array $dispatchAreas,
    ) {
    }

    public function resolve(?string $postalCode, ?string $city, ?string $dispatchAreaName): ?HospitalPopulationCoordinates
    {
        if (null !== $postalCode && '' !== $postalCode) {
            $normalizedPostalCode = trim($postalCode);
            if (isset($this->postalCodes[$normalizedPostalCode])) {
                return $this->toCoordinates($this->postalCodes[$normalizedPostalCode]);
            }
        }

        if (null !== $city && '' !== $city) {
            $normalizedCity = trim($city);
            if (isset($this->cities[$normalizedCity])) {
                return $this->toCoordinates($this->cities[$normalizedCity]);
            }
        }

        if (null !== $dispatchAreaName && '' !== $dispatchAreaName) {
            $normalizedArea = trim($dispatchAreaName);
            if (isset($this->dispatchAreas[$normalizedArea])) {
                return $this->toCoordinates($this->dispatchAreas[$normalizedArea]);
            }
        }

        return null;
    }

    /**
     * @param array{latitude: float|int, longitude: float|int} $entry
     */
    private function toCoordinates(array $entry): HospitalPopulationCoordinates
    {
        return new HospitalPopulationCoordinates(
            (float) $entry['latitude'],
            (float) $entry['longitude'],
        );
    }
}
