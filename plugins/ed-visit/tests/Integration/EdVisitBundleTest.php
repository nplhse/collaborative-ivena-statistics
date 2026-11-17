<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Integration;

use Cois\Plugin\EdVisit\EdVisitBundle;
use Doctrine\Bundle\DoctrineBundle\Mapping\MappingDriver;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The bundle contributes its Doctrine mapping, migrations, assets and Stimulus controllers by itself and keeps
 * the application's own paths (#1300, ADR 037).
 */
final class EdVisitBundleTest extends KernelTestCase
{
    public function testTheBundleIsEnabledInEveryEnvironment(): void
    {
        self::bootKernel();

        self::assertContains(EdVisitBundle::class, self::getContainer()->getParameter('kernel.bundles'));
        $bundles = require $this->projectDir().'/config/bundles.php';
        self::assertSame(['all' => true], $bundles[EdVisitBundle::class]);
    }

    public function testItRegistersItsDoctrineMappingAndMigrations(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $driver = $em->getConfiguration()->getMetadataDriverImpl();
        self::assertInstanceOf(MappingDriver::class, $driver);
        $driver = $driver->getDriver();
        self::assertInstanceOf(MappingDriverChain::class, $driver);
        self::assertArrayHasKey('Cois\Plugin\EdVisit\Entity', $driver->getDrivers());

        $migrations = self::getContainer()->get('doctrine.migrations.dependency_factory');
        self::assertInstanceOf(DependencyFactory::class, $migrations);
        $directories = $migrations->getConfiguration()->getMigrationDirectories();
        self::assertSame(realpath($this->projectDir().'/plugins/ed-visit/migrations'), realpath($directories['Cois\Plugin\EdVisit\Migrations']));
        self::assertArrayHasKey('DoctrineMigrations', $directories, 'The application keeps its migrations.');
    }

    public function testItAddsItsAssetsAndStimulusControllersNextToTheApplications(): void
    {
        self::bootKernel();

        $assetPaths = $this->config('framework', 'asset_mapper.paths');
        self::assertStringContainsString('plugins/ed-visit/assets', $assetPaths);
        self::assertStringContainsString('assets/', $assetPaths);

        $controllerPaths = $this->config('stimulus', 'controller_paths');
        self::assertStringContainsString('plugins/ed-visit/assets/controllers', $controllerPaths);
        self::assertMatchesRegularExpression('#/assets/controllers\b#', str_replace('plugins/ed-visit/assets/controllers', '', $controllerPaths));
    }

    private function projectDir(): string
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);

        return $projectDir;
    }

    private function config(string $extension, string $path): string
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester(new Application(self::$kernel)->find('debug:config'));
        self::assertSame(0, $tester->execute(['name' => $extension, 'path' => $path]));

        return $tester->getDisplay();
    }
}
