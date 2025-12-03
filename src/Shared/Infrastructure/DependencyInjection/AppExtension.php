<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class AppExtension extends Extension
{
    #[\Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new AppConfiguration();
        $config = $this->processConfiguration($configuration, $configs);

        // General settings
        $container->setParameter('app.title', $config['title']);
        $container->setParameter('app.short_title', $config['short_title']);
        $container->setParameter('app.default.locale', $config['default_locale']);
    }
}
