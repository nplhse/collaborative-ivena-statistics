<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Application\Navigation\Menu\Inspection;

use App\Shared\Application\Navigation\Menu\Inspection\RouteMethodCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\ConfigCacheFactory;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;

/**
 * The route list of the navigation inspector is read from the router once and then served from the cache (#1343).
 */
final class RouteMethodCatalogTest extends TestCase
{
    private string $dir;

    private int $routerReads = 0;

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/route_method_catalog_test_'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->dir);
        file_put_contents($this->dir.'/routes.php', 'v1');
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    public function testItListsEveryRouteWithItsMethods(): void
    {
        self::assertSame(
            ['app_page' => [], 'app_export' => ['POST'], 'app_form' => ['GET', 'POST']],
            $this->catalog(debug: true)->all(),
        );
    }

    public function testItReadsTheRouterOnlyOnce(): void
    {
        $this->catalog(debug: true)->all();
        $this->catalog(debug: true)->all();
        $this->catalog(debug: false)->all();

        self::assertSame(1, $this->routerReads);
    }

    public function testWithDebugAChangedRoutingResourceRebuildsTheList(): void
    {
        $this->catalog(debug: true)->all();
        touch($this->dir.'/routes.php', time() + 10);

        $this->catalog(debug: true)->all();

        self::assertSame(2, $this->routerReads);
    }

    public function testWithoutDebugTheListStaysUntilTheCacheIsCleared(): void
    {
        $this->catalog(debug: false)->all();
        touch($this->dir.'/routes.php', time() + 10);

        $this->catalog(debug: false)->all();

        self::assertSame(1, $this->routerReads);
    }

    private function catalog(bool $debug): RouteMethodCatalog
    {
        $router = new Router(new ClosureLoader(), function (): RouteCollection {
            ++$this->routerReads;
            $routes = new RouteCollection();
            $routes->add('app_page', new Route('/page'));
            $routes->add('app_export', new Route('/export', methods: ['POST']));
            $routes->add('app_form', new Route('/form', methods: ['GET', 'POST']));
            $routes->addResource(new FileResource($this->dir.'/routes.php'));

            return $routes;
        });

        return new RouteMethodCatalog($router, new ConfigCacheFactory($debug), $this->dir.'/cache');
    }
}
