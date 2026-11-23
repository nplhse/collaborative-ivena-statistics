<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contracts;

interface DistrictBoundarySourcesInterface
{
    /**
     * The distinct provenance of the stored district boundaries, without any district or hospital.
     *
     * @return list<array{provider: string|null, licence: string|null, attribution: string}>
     */
    public function all(): array;
}
