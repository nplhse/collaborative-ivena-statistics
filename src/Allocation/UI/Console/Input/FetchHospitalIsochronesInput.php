<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;

final class FetchHospitalIsochronesInput
{
    #[Option(description: 'Preview hospitals and files without calling OpenRouteService (default)', name: 'dry-run')]
    public bool $dryRun = false;

    #[Option(description: 'Fetch missing isochrones and write GeoJSON files', name: 'apply')]
    public bool $apply = false;

    #[Option(description: 'Overwrite existing isochrone files', name: 'force')]
    public bool $force = false;

    #[Option(description: 'Pause between OpenRouteService requests in milliseconds (default: 3500)', name: 'delay-ms')]
    public int $delayMs = 3500;
}
