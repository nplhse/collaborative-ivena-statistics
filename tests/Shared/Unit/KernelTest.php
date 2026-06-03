<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class KernelTest extends KernelTestCase
{
    public function testPrepareContainerRegistersAppVersionParameter(): void
    {
        $container = new ContainerBuilder();
        $kernel = new Kernel('test', true);

        $prepareContainer = new \ReflectionMethod(Kernel::class, 'prepareContainer');
        $prepareContainer->invoke($kernel, $container);

        self::assertIsString($container->getParameter('app.version'));
        self::assertSame(Kernel::APP_VERSION, $container->getParameter('app.version'));
    }

    public function testBootedContainerExposesAppVersionParameter(): void
    {
        self::bootKernel();

        self::assertIsString(self::getContainer()->getParameter('app.version'));
        self::assertSame(Kernel::APP_VERSION, self::getContainer()->getParameter('app.version'));
    }
}
