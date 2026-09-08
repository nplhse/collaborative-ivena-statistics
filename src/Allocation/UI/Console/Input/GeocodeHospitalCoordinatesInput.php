<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;

final class GeocodeHospitalCoordinatesInput
{
    #[Option(description: 'Preview hospitals without calling OpenRouteService (default)', name: 'dry-run')]
    public bool $dryRun = false;

    #[Option(description: 'Geocode addresses and write hospital coordinates', name: 'apply')]
    public bool $apply = false;

    #[Option(description: 'Overwrite existing hospital coordinates', name: 'force')]
    public bool $force = false;

    #[Option(description: 'Pause between OpenRouteService requests in milliseconds (default: 3500)', name: 'delay-ms')]
    public int $delayMs = 3500;
}
