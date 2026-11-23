<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contracts;

use App\Allocation\Application\Geo\DTO\DispatchAreaGeoJson;
use App\Allocation\Application\Geo\DTO\DispatchAreaGeoSnapshot;

/**
 * The dispatch areas that have a complete map shape, read from the database (ADR 041).
 */
interface DispatchAreaGeoIndexInterface
{
    public function snapshot(): DispatchAreaGeoSnapshot;

    public function geoJson(): DispatchAreaGeoJson;
}
