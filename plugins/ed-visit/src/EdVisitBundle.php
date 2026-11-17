<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit;

use App\Encounter\Contract\Plugin\EncounterPluginBundleInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Encounter schema plugin `ed_visit`: ED visits with MTS (ADR 037, #1300). It contributes everything through
 * the bundle: services (`config/services.php`), Doctrine mapping (`src/Domain/Entity`), migrations (`migrations/`),
 * assets and Stimulus controllers (`assets/`), routes (`config/routes.php`) and templates (`@EdVisit`). From the application it uses only `App\Encounter\Contract\…`.
 *
 * @psalm-suppress UnusedClass Enabled in config/bundles.php.
 */
final class EdVisitBundle extends AbstractBundle implements EncounterPluginBundleInterface
{
    #[\Override]
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    #[\Override]
    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('doctrine', ['orm' => ['mappings' => ['EdVisit' => [
            'type' => 'attribute',
            'is_bundle' => false,
            'dir' => $this->getPath().'/src/Domain/Entity',
            'prefix' => __NAMESPACE__.'\Domain\Entity',
        ]]]]);
        $container->prependExtensionConfig('doctrine_migrations', ['migrations_paths' => [
            __NAMESPACE__.'\Migrations' => $this->getPath().'/migrations',
        ]]);
        $container->prependExtensionConfig('framework', ['asset_mapper' => ['paths' => [
            $this->getPath().'/assets',
        ]]]);
        $container->prependExtensionConfig('stimulus', ['controller_paths' => [
            $this->getPath().'/assets/controllers',
        ]]);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    #[\Override]
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $configurator->import('../config/services.php');
    }
}
