<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Command;

use App\Shared\Infrastructure\Maintenance\MaintenanceMode;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MaintenanceCommandTest extends KernelTestCase
{
    public function testSwitchesTheMaintenanceModeOnAndOff(): void
    {
        self::bootKernel();
        $mode = self::getContainer()->get(MaintenanceMode::class);
        self::assertInstanceOf(MaintenanceMode::class, $mode);
        $mode->disable();
        $tester = new CommandTester(new Application(self::$kernel)->find('app:maintenance'));

        try {
            self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'on']));
            self::assertTrue($mode->isEnabled());
            self::assertStringContainsString('Maintenance mode is on since', $tester->getDisplay());

            self::assertSame(Command::SUCCESS, $tester->execute([]));
            self::assertStringContainsString('Maintenance mode is on since', $tester->getDisplay());

            self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'off']));
            self::assertFalse($mode->isEnabled());
            self::assertStringContainsString('Maintenance mode is off.', $tester->getDisplay());

            self::assertSame(Command::INVALID, $tester->execute(['action' => 'maybe']));
        } finally {
            $mode->disable();
        }
    }
}
