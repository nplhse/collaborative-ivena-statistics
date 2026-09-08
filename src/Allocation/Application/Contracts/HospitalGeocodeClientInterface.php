<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contracts;

use App\Allocation\Application\Hospital\DTO\HospitalGeocodeOutcome;

/**
 * OpenRouteService (or compatible) street geocoding. Used by the geocode command only.
 */
interface HospitalGeocodeClientInterface
{
    public function hasApiKey(): bool;

    public function geocodeAddress(string $street, string $postalCode, string $city, string $country): HospitalGeocodeOutcome;
}
