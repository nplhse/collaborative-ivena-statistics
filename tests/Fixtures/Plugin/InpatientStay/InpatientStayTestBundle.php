<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Plugin\InpatientStay;

use App\Encounter\Contract\Plugin\EncounterPluginBundleInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Test-only encounter schema plugin `inpatient_stay` (ADR 037, #1274), enabled in `config/bundles.php` for the
 * `test` environment only. It contributes everything a schema bundle can, through the bundle alone: services,
 * Doctrine mapping, migrations path, routes (`config/routes.php`), templates (`@InpatientStayTest`) and
 * translations (domain `inpatient_stay_test`). It uses only `App\Encounter\Contract\…`.
 */
final class InpatientStayTestBundle extends AbstractBundle implements EncounterPluginBundleInterface
{
    #[\Override]
    public function getPath(): string
    {
        return __DIR__;
    }

    #[\Override]
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', ['orm' => ['mappings' => ['InpatientStayTest' => [
            'type' => 'attribute',
            'is_bundle' => false,
            'dir' => $this->getPath().'/Entity',
            'prefix' => __NAMESPACE__.'\Entity',
        ]]]]);
        $builder->prependExtensionConfig('doctrine_migrations', ['migrations_paths' => [
            __NAMESPACE__.'\Migrations' => $this->getPath().'/Migrations',
        ]]);
    }

    /**
     * @param array<string, mixed> $config
     */
    #[\Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('config/services.php');
    }
}
