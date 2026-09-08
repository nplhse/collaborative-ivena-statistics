<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contracts;

use App\Allocation\Domain\Entity\Hospital;

/**
 * Stored destination isochrones for a hospital (5-minute bands as GeoJSON).
 */
interface HospitalIsochroneProviderInterface
{
    /**
     * @return array{type: string, features: list<array<string, mixed>>, properties?: array<string, mixed>}|null
     */
    public function findForHospital(Hospital $hospital): ?array;
}
