<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Demo;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Boots the demo environment (ADR 023) and checks what keeps it apart from dev and production.
 * Debug mode, so a demo container compiled from older code is rebuilt instead of reused.
 */
final class DemoEnvironmentConfigTest extends KernelTestCase
{
    public function testDemoEnvironmentUsesOwnStorageAndDemoMode(): void
    {
        $container = $this->bootDemoKernel();
        $projectDir = $container->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);

        self::assertTrue($container->getParameter('app.demo_mode'));
        self::assertSame($projectDir.'/var/demo/imports', $container->getParameter('app.imports_base_dir'));
        self::assertSame($projectDir.'/var/demo/imports/rejects', $container->getParameter('app.rejects_base_dir'));
        self::assertSame($projectDir.'/public/uploads/demo/media', $container->getParameter('app.media_upload_dir'));
        self::assertSame($projectDir.'/var/demo/geo/hospital-isochrones', $container->getParameter('app.hospital_isochrones_dir'));
        self::assertSame($projectDir.'/var/demo/geo/prepared-locations', $container->getParameter('app.prepared_locations_dir'));
        self::assertSame('large', $container->getParameter('fixtures.profile'));
    }

    public function testDemoEnvironmentUsesOwnCookieNames(): void
    {
        $container = $this->bootDemoKernel();

        self::assertSame('demo_', $container->getParameter('app.cookie_prefix'));
        $sessionOptions = $container->getParameter('session.storage.options');
        self::assertIsArray($sessionOptions);
        self::assertSame('demo_PHPSESSID', $sessionOptions['name'] ?? null);
    }

    public function testDemoEnvironmentHasNoProfilerOrDebugTools(): void
    {
        $container = $this->bootDemoKernel();
        $bundles = $container->getParameter('kernel.bundles');
        self::assertIsArray($bundles);

        self::assertArrayHasKey('DoctrineFixturesBundle', $bundles);
        self::assertArrayHasKey('ZenstruckFoundryBundle', $bundles);
        self::assertArrayNotHasKey('WebProfilerBundle', $bundles);
        self::assertArrayNotHasKey('DebugBundle', $bundles);
        self::assertArrayNotHasKey('DAMADoctrineTestBundle', $bundles);
    }

    public function testOtherEnvironmentsAreNotInDemoMode(): void
    {
        self::bootKernel();

        self::assertFalse(self::getContainer()->getParameter('app.demo_mode'));
        $sessionOptions = self::getContainer()->getParameter('session.storage.options');
        self::assertIsArray($sessionOptions);
        self::assertArrayNotHasKey('name', $sessionOptions);
    }

    private function bootDemoKernel(): ContainerInterface
    {
        return self::bootKernel(['environment' => 'demo', 'debug' => true])->getContainer();
    }
}
