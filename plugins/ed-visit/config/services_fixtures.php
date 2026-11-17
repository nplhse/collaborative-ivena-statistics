<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Synthetic ED data (fixtures, generator command). Loaded only where the fixtures bundle is installed (dev, test,
// demo), as the application's src/DataFixtures (config/services/foundry.yaml).
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->defaults()->autowire()->autoconfigure()
        ->load('Cois\\Plugin\\EdVisit\\DataFixtures\\', '../src/DataFixtures/');
};
