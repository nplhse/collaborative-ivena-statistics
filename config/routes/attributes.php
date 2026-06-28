<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    // Prod-safe: scan only HTTP controllers. Foundry factories, Faker providers,
    // and DataFixtures live elsewhere under src/ and depend on dev packages.
    $routes->import('../../src/**/UI/Http/Controller/', 'attribute');
};
