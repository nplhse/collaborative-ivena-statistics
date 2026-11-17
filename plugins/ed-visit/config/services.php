<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->defaults()->autowire()->autoconfigure()
        ->load('Cois\\Plugin\\EdVisit\\', '../src/')
        ->exclude(['../src/Domain/Entity/', '../src/EdVisitBundle.php']);
};
