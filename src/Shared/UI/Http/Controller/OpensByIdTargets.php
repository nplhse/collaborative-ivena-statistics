<?php

declare(strict_types=1);

namespace App\Shared\UI\Http\Controller;

use App\Shared\UI\Http\Routing\OpensById;
use Symfony\Component\Config\ConfigCacheFactoryInterface;
use Symfony\Component\Config\ConfigCacheInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * Finds the Explore detail route marked with {@see OpensById} for an object type (the `{type}`
 * path segment), so new detail pages only need the attribute (#1354).
 *
 * Loading the route collection reads every route resource and reflects the controllers, which is far too slow for each
 * request (#1601). The type map is therefore written once to a config cache file next to the compiled router and only
 * rebuilt when a route resource changes, as the router does for its matcher.
 */
final readonly class OpensByIdTargets
{
    private const string PATH = '#^/explore/([a-z_]+)/\{\w+\}$#';

    private const string CACHE_FILE = '/opens_by_id_targets.php';

    public function __construct(
        private RouterInterface $router,
        #[Autowire(service: 'config_cache_factory')]
        private ConfigCacheFactoryInterface $configCacheFactory,
        #[Autowire(param: 'kernel.cache_dir')]
        private string $cacheDir,
    ) {
    }

    public function find(string $type): ?OpensByIdTarget
    {
        $target = $this->targets()[$type] ?? null;

        return null === $target ? null : new OpensByIdTarget($target['route'], $target['entity'], $target['parameter']);
    }

    /**
     * @return array<string, array{route: string, entity: class-string, parameter: string}>
     */
    private function targets(): array
    {
        $cache = $this->configCacheFactory->cache($this->cacheDir.self::CACHE_FILE, function (ConfigCacheInterface $cache): void {
            $routes = $this->router->getRouteCollection();
            $cache->write('<?php return '.var_export($this->scan($routes), true).';'.\PHP_EOL, $routes->getResources());
        });

        /** @var array<string, array{route: string, entity: class-string, parameter: string}> $targets */
        $targets = require $cache->getPath();

        return $targets;
    }

    /**
     * @return array<string, array{route: string, entity: class-string, parameter: string}>
     */
    private function scan(RouteCollection $routes): array
    {
        $targets = [];
        foreach ($routes->all() as $name => $route) {
            if (1 !== preg_match(self::PATH, $route->getPath(), $match) || isset($targets[$match[1]])) {
                continue;
            }

            $attribute = $this->attributeOf($route->getDefault('_controller'));
            $variables = $route->compile()->getPathVariables();
            if (!$attribute instanceof OpensById || 1 !== \count($variables)) {
                continue;
            }

            $targets[$match[1]] = ['route' => $name, 'entity' => $attribute->entity, 'parameter' => $variables[0]];
        }

        return $targets;
    }

    private function attributeOf(mixed $controller): ?OpensById
    {
        if (!\is_string($controller)) {
            return null;
        }

        $parts = explode('::', $controller, 2);
        $class = $parts[0];
        $method = $parts[1] ?? '__invoke';
        if (!class_exists($class) || !method_exists($class, $method)) {
            return null;
        }

        $attributes = new \ReflectionMethod($class, $method)->getAttributes(OpensById::class);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }
}
