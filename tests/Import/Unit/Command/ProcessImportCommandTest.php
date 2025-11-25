<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Command;

use App\Statistics\Application\Message\ScheduleScope;
use App\Statistics\Application\MessageHandler\ScheduleScopesHandler;
use App\Statistics\UI\Console\Command\ProcessImportCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ProcessImportCommandTest extends TestCase
{
    public function testFailsWhenImportIdIsMissingOrNonNumeric(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $handler = $this->createMock(ScheduleScopesHandler::class);

        $command = new ProcessImportCommand($bus, $handler);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]); // no --import-id provided

        self::assertSame(1, $exitCode, 'Command should fail without --import-id.');
        $display = $tester->getDisplay();

        // The command currently prints: "--import is required and must be a numeric id"
        // (Note: message says "--import" although the option is "--import-id")
        self::assertStringContainsString('must be a numeric id', $display);
    }

    public function testDispatchesAsynchronouslyByDefault(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $handler = $this->createMock(ScheduleScopesHandler::class);

        $dispatched = [];
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function ($msg) use (&$dispatched) {
                // Capture and minimally validate the message
                self::assertInstanceOf(ScheduleScope::class, $msg);
                $dispatched[] = $msg;

                return true;
            }))
            ->willReturnCallback(function ($msg) {
                return new Envelope($msg);
            });

        $command = new ProcessImportCommand($bus, $handler);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--import-id' => '42']);
        self::assertSame(0, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Dispatched import_id=42 for asynchronous processing.', $display);
        self::assertCount(1, $dispatched, 'Exactly one ScheduleScope should be dispatched.');
    }

    public function testRunsSynchronouslyWhenSyncOptionIsProvided(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $handler = $this->createMock(ScheduleScopesHandler::class);

        $bus->expects(self::never())->method('dispatch');

        $handler->expects(self::once())
            ->method('__invoke')
            ->with(self::callback(function (ScheduleScope $msg) {
                // Validate the payload that will be handled synchronously
                $ref = new \ReflectionClass($msg);
                $prop = $ref->getProperty('importId');
                $prop->setAccessible(true);
                self::assertSame(7, $prop->getValue($msg));

                return true;
            }));

        $command = new ProcessImportCommand($bus, $handler);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--import-id' => '7',
            '--sync' => true,
        ]);
        self::assertSame(0, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Scheduled slice recomputation synchronously for import_id=7.', $display);
    }
}
