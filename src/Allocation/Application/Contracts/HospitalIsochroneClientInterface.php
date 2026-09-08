<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contracts;

/**
 * OpenRouteService (or compatible) destination isochrones. Used by the fetch command only.
 */
interface HospitalIsochroneClientInterface
{
    public function hasApiKey(): bool;

    /**
     * @return array{type: string, features: list<array<string, mixed>>}|null
     */
    public function fetchDestinationIsochrones(float $latitude, float $longitude): ?array;
}
