<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\UI\Console\Command;

use App\Shared\Application\PublicId\PublicIdBackfillExitCode;
use App\Shared\Application\PublicId\PublicIdBackfillRunControl;
use App\Shared\Application\PublicId\PublicIdBackfillService;
use App\Shared\UI\Console\Command\BackfillPublicIdsCommand;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BackfillPublicIdsCommandTest extends TestCase
{
    public function testDryRunReturnsSuccess(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(2);

        $tester = $this->createCommandTester($connection);

        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(PublicIdBackfillExitCode::SUCCESS, $exitCode);
        self::assertStringContainsString('Dry run finished', $tester->getDisplay());
        self::assertStringContainsString('Would update', $tester->getDisplay());
    }

    public function testCompletedBackfillReturnsSuccess(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([]);
        $connection->method('fetchOne')->willReturn(0);

        $tester = $this->createCommandTester($connection);

        $exitCode = $tester->execute([]);

        self::assertSame(PublicIdBackfillExitCode::SUCCESS, $exitCode);
        self::assertStringContainsString('Backfill finished in', $tester->getDisplay());
    }

    public function testIncompleteBackfillReturnsMoreWork(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')
            ->willReturnCallback(static function (): array {
                usleep(1_100_000);

                return [42];
            });
        $connection->method('executeStatement')->willReturn(1);
        $connection->method('fetchOne')
            ->willReturnCallback(static fn (string $sql): int => str_contains($sql, 'FROM hospital') ? 1 : 0);

        $tester = $this->createCommandTester($connection);

        $exitCode = $tester->execute([
            '--table' => ['hospital'],
            '--batch-size' => 1,
            '--max-runtime' => 1,
        ]);

        self::assertSame(PublicIdBackfillExitCode::MORE_WORK, $exitCode);
        self::assertStringContainsString('Backfill paused after', $tester->getDisplay());
    }

    public function testInterruptedBackfillReturnsMoreWork(): void
    {
        $connection = $this->createMock(Connection::class);
        $command = new BackfillPublicIdsCommand(new PublicIdBackfillService($connection));
        $connection->method('fetchFirstColumn')->willReturn([10]);
        $connection->method('executeStatement')->willReturnCallback(
            static function () use ($command): int {
                $command->handleSignal(\SIGINT);

                return 1;
            },
        );

        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--table' => ['hospital']]);

        self::assertSame(PublicIdBackfillExitCode::MORE_WORK, $exitCode);
        self::assertStringContainsString('Interrupted by signal 2', $tester->getDisplay());
    }

    public function testCriticalFailureReturnsCriticalExitCode(): void
    {
        $connection = $this->createMock(Connection::class);

        $tester = $this->createCommandTester($connection);

        $exitCode = $tester->execute(['--table' => ['unknown_table']]);

        self::assertSame(PublicIdBackfillExitCode::CRITICAL, $exitCode);
        self::assertStringContainsString('Unknown table(s)', $tester->getDisplay());
    }

    public function testHandleSignalRequestsStop(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([]);
        $connection->method('fetchOne')->willReturn(0);

        $command = new BackfillPublicIdsCommand(new PublicIdBackfillService($connection));
        $tester = new CommandTester($command);
        $tester->execute(['--table' => ['hospital']]);

        self::assertFalse($command->handleSignal(\SIGTERM));

        $runControl = $this->readRunControl($command);
        self::assertTrue($runControl->isStopRequested());
        self::assertSame(\SIGTERM, $runControl->getSignal());
    }

    public function testSubscribedSignalsDependsOnPcntl(): void
    {
        $command = new BackfillPublicIdsCommand(new PublicIdBackfillService($this->createMock(Connection::class)));

        $signals = $command->getSubscribedSignals();

        if (\function_exists('pcntl_signal')) {
            self::assertSame([\SIGINT, \SIGTERM], $signals);
        } else {
            self::assertSame([], $signals);
        }
    }

    private function createCommandTester(Connection $connection): CommandTester
    {
        return new CommandTester(new BackfillPublicIdsCommand(new PublicIdBackfillService($connection)));
    }

    private function readRunControl(BackfillPublicIdsCommand $command): PublicIdBackfillRunControl
    {
        $property = new \ReflectionProperty(BackfillPublicIdsCommand::class, 'runControl');
        $runControl = $property->getValue($command);

        self::assertInstanceOf(PublicIdBackfillRunControl::class, $runControl);

        return $runControl;
    }
}
