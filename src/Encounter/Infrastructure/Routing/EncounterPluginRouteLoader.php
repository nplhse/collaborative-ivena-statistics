<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Routing;

use App\Encounter\Contract\Plugin\EncounterPluginBundleInterface;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route type `encounter_plugins` (`config/routes/encounter_plugins.php`): imports `config/routes.php` of every
 * enabled bundle that implements EncounterPluginBundleInterface (ADR 037). Bundles without the file
 * contribute no routes.
 *
 * @psalm-suppress UnusedClass Tagged as a routing loader.
 */
#[AutoconfigureTag('routing.loader')]
final class EncounterPluginRouteLoader extends Loader
{
    public const string TYPE = 'encounter_plugins';

    /**
     * @param array<string, class-string>                           $bundles
     * @param array<string, array{path: string, namespace: string}> $bundlesMetadata
     */
    public function __construct(
        #[Autowire(param: 'kernel.bundles')]
        private readonly array $bundles,
        #[Autowire(param: 'kernel.bundles_metadata')]
        private readonly array $bundlesMetadata,
        #[Autowire(param: 'kernel.environment')]
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    #[\Override]
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        foreach ($this->bundles as $name => $class) {
            if (!is_a($class, EncounterPluginBundleInterface::class, true)) {
                continue;
            }

            $file = $this->bundlesMetadata[$name]['path'].'/config/routes.php';
            if (!is_file($file)) {
                continue;
            }

            $imported = $this->import($file);
            if ($imported instanceof RouteCollection) {
                $routes->addCollection($imported);
            }
        }

        return $routes;
    }

    #[\Override]
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return self::TYPE === $type;
    }
}
