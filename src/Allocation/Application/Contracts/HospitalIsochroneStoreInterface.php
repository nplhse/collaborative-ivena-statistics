<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contracts;

use App\Allocation\Domain\Entity\Hospital;

/**
 * File-backed hospital isochrone catalog (read and write).
 */
interface HospitalIsochroneStoreInterface extends HospitalIsochroneProviderInterface
{
    public function existsForHospital(Hospital $hospital): bool;

    /**
     * @param array{type: string, features: list<array<string, mixed>>, properties?: array<string, mixed>} $geojson
     */
    public function writeForHospital(Hospital $hospital, array $geojson): void;
}
