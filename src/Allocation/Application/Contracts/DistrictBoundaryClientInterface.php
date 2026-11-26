<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contracts;

use App\Allocation\Application\Geo\DTO\DistrictBoundaryOutcome;

/**
 * Fetches the boundary of one district by its official key. Called only from the job an admin started (ADR 041).
 */
interface DistrictBoundaryClientInterface
{
    public function fetch(string $ags): DistrictBoundaryOutcome;
}
