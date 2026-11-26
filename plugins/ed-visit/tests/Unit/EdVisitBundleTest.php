<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Unit;

use Cois\Plugin\EdVisit\EdVisitBundle;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame([
            ['messenger' => ['routing' => [\Cois\Plugin\EdVisit\Application\Message\MatchEncountersMessage::class => 'sync']]],
            ['asset_mapper' => ['paths' => [$root.'/assets']]],
        ], $container->getExtensionConfig('framework'), 'Tests run the messages synchronously.');
        self::assertSame([['controller_paths' => [$root.'/assets/controllers']]], $container->getExtensionConfig('stimulus'));

        foreach (['/src/Domain/Entity', '/migrations', '/assets/controllers'] as $directory) {
            self::assertDirectoryExists($root.$directory);
        }
    }

    public function testOutsideTestsItRoutesNoMessage(): void
    {
        $container = $this->container('prod');
        $extension = new EdVisitBundle()->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);

        $extension->prepend($container);

        self::assertSame([['asset_mapper' => ['paths' => [new EdVisitBundle()->getPath().'/assets']]]], $container->getExtensionConfig('framework'));
    }

    #[DataProvider('environments')]
    public function testItLoadsItsServicesAndTheSyntheticDataOnlyWithTheFixturesBundle(string $environment, bool $fixtures): void
    {
        $container = $this->container($environment);
        $extension = new EdVisitBundle()->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([], $container);

        $files = array_map(
            static fn (FileResource $resource): string => $resource->getResource(),
            array_filter($container->getResources(), static fn (object $resource): bool => $resource instanceof FileResource),
        );
        $root = new EdVisitBundle()->getPath();
        self::assertContains(realpath($root.'/config/services.php'), $files);
        self::assertSame($fixtures, \in_array(realpath($root.'/config/services_fixtures.php'), $files, true));
        self::assertSame($fixtures, $container->hasDefinition(\Cois\Plugin\EdVisit\DataFixtures\EncounterFixture::class));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function environments(): iterable
    {
        yield 'test' => ['test', true];
        yield 'dev' => ['dev', true];
        yield 'demo' => ['demo', true];
        yield 'prod' => ['prod', false];
    }

    private function container(string $environment = 'test'): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', $environment);
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
