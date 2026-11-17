<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Unit;

use Cois\Plugin\EdVisit\EdVisitBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

/**
 * What the bundle contributes to the configuration of the application (#1300, ADR 037), without a kernel.
 */
final class EdVisitBundleTest extends TestCase
{
    public function testItPrependsMappingMigrationsAssetsAndStimulusPaths(): void
    {
        $bundle = new EdVisitBundle();
        $root = $bundle->getPath();
        $container = $this->container();

        $extension = $bundle->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        $extension->prepend($container);

        self::assertSame([['orm' => ['mappings' => ['EdVisit' => [
            'type' => 'attribute',
            'is_bundle' => false,
            'dir' => $root.'/src/Domain/Entity',
            'prefix' => 'Cois\Plugin\EdVisit\Domain\Entity',
        ]]]]], $container->getExtensionConfig('doctrine'));
        self::assertSame([['migrations_paths' => ['Cois\Plugin\EdVisit\Migrations' => $root.'/migrations']]], $container->getExtensionConfig('doctrine_migrations'));
        self::assertSame([['asset_mapper' => ['paths' => [$root.'/assets']]]], $container->getExtensionConfig('framework'));
        self::assertSame([['controller_paths' => [$root.'/assets/controllers']]], $container->getExtensionConfig('stimulus'));

        foreach (['/src/Domain/Entity', '/migrations', '/assets/controllers'] as $directory) {
            self::assertDirectoryExists($root.$directory);
        }
    }

    public function testItLoadsItsServices(): void
    {
        $container = $this->container();
        $extension = new EdVisitBundle()->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([], $container);

        $files = array_map(
            static fn (FileResource $resource): string => $resource->getResource(),
            array_filter($container->getResources(), static fn (object $resource): bool => $resource instanceof FileResource),
        );
        self::assertContains(realpath(new EdVisitBundle()->getPath().'/config/services.php'), $files);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        foreach (['doctrine', 'doctrine_migrations', 'framework', 'stimulus'] as $name) {
            $container->registerExtension(new class($name) extends Extension {
                public function __construct(private readonly string $name)
                {
                }

                #[\Override]
                public function load(array $configs, ContainerBuilder $container): void
                {
                }

                #[\Override]
                public function getAlias(): string
                {
                    return $this->name;
                }
            });
        }

        return $container;
    }
}
