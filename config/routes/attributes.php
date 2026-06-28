<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    // Prod-safe: scan src/ for attribute routes but exclude dev-only directories
    // (Foundry factories, Faker providers, fixtures) that depend on require-dev packages.
    $routes->import('../../src/', 'attribute', false, [
        '../../src/DataFixtures',
        '../../src/**/Infrastructure/Factory',
        '../../src/**/Infrastructure/Faker',
        '../../src/**/Domain/Factory',
    ]);
};
