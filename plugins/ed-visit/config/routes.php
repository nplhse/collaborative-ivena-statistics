<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Imported by EncounterPluginRouteLoader (ADR 037); route names and paths are those of the former core pages.
return static function (RoutingConfigurator $routes): void {
    $routes->import('../src/UI/Http/Controller/', 'attribute');
};
