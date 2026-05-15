<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('../../src/', 'attribute', false, [
        '../../src/DataFixtures',
        '../../src/Allocation/Infrastructure/Factory',
        '../../src/Allocation/Infrastructure/Faker',
        '../../src/Content/Infrastructure/Factory',
        '../../src/Import/Infrastructure/Factory',
        '../../src/User/Domain/Factory',
    ]);
};
