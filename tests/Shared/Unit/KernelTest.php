<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KernelTest extends KernelTestCase
{
    public function testVersionConstantMatchesContainerParameter(): void
    {
        self::bootKernel();

        self::assertIsString(self::getContainer()->getParameter('app.version'));
        self::assertSame(Kernel::APP_VERSION, self::getContainer()->getParameter('app.version'));
    }
}
