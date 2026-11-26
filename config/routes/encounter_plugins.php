<?php

declare(strict_types=1);

use App\Encounter\Infrastructure\Routing\EncounterPluginRouteLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Routes of enabled encounter schema bundles (ADR 037), imported from each bundle's config/routes.php.
return static function (RoutingConfigurator $routes): void {
    $routes->import('.', EncounterPluginRouteLoader::TYPE);
};
