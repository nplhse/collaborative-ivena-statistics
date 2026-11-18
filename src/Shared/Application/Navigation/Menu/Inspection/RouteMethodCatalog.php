<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu\Inspection;

use Symfony\Component\Config\ConfigCacheFactoryInterface;
use Symfony\Component\Config\ConfigCacheInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every route name with its HTTP methods, for the navigation inspector (#1343).
 *
 * RouterInterface::getRouteCollection() is not cached by Symfony (only the compiled matcher and generator are):
 * every call reads the route attributes of all controllers again, which took about a second per request on the
 * admin page and far longer in the test suite. The list is cached the way the router caches itself: with debug, it
 * is rebuilt when a routing resource changes; without debug, it stays until the cache is cleared (on deploy).
 */
final readonly class RouteMethodCatalog
{
    private const string CACHE_FILE = '/navigation/route_methods.php';

    public function __construct(
        private RouterInterface $router,
        #[Autowire(service: 'config_cache_factory')]
        private ConfigCacheFactoryInterface $configCacheFactory,
        #[Autowire(param: 'kernel.cache_dir')]
        private string $cacheDir,
    ) {
    }

    /**
     * @return array<string, list<string>> route name => allowed methods (empty: any method)
     */
    public function all(): array
    {
        $cache = $this->configCacheFactory->cache($this->cacheDir.self::CACHE_FILE, function (ConfigCacheInterface $cache): void {
            $routes = $this->router->getRouteCollection();
            $methods = array_map(static fn (Route $route): array => $route->getMethods(), $routes->all());

            $cache->write('<?php return '.var_export($methods, true).';'.\PHP_EOL, $routes->getResources());
        });

        /** @var array<string, list<string>> $methods */
        $methods = require $cache->getPath();

        return $methods;
    }
}
