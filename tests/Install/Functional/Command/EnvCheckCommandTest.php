<?php

declare(strict_types=1);

namespace App\Tests\Install\Functional\Command;

use App\Install\UI\Console\Command\EnvCheckCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class EnvCheckCommandTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    public function testDevProfilePassesWithWarningsAndDoesNotExposeSecrets(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([
            '--check-profile' => 'dev',
            '--skip-database' => true,
        ]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('APP_SECRET', $display);
        self::assertStringContainsString('WARN', $display);
        self::assertStringNotContainsString('ecretf0rt3st', $display);
        self::assertStringNotContainsString('password@', $display);
    }

    public function testBetaProfileFailsWhenSentryMissing(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([
            '--check-profile' => 'beta',
            '--skip-database' => true,
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('SENTRY_DSN', $tester->getDisplay());
        self::assertStringContainsString('FAIL', $tester->getDisplay());
    }

    public function testCommandIsRegisteredNextToInstallCommand(): void
    {
        self::assertTrue(self::getContainer()->has(EnvCheckCommand::class));
        self::assertTrue(self::getContainer()->has(\App\Install\UI\Console\Command\InstallCommand::class));
    }

    private function createCommandTester(): CommandTester
    {
        /** @var EnvCheckCommand $command */
        $command = self::getContainer()->get(EnvCheckCommand::class);

        return new CommandTester($command);
    }
}
