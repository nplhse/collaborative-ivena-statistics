<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contracts;

use App\Allocation\Application\Hospital\DTO\HospitalIsochroneFetchOutcome;

/**
 * OpenRouteService (or compatible) destination isochrones. Used by the fetch command only.
 */
interface HospitalIsochroneClientInterface
{
    public function hasApiKey(): bool;

    public function fetchDestinationIsochrones(float $latitude, float $longitude): HospitalIsochroneFetchOutcome;
}
