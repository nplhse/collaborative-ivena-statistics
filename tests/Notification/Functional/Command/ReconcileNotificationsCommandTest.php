<?php

declare(strict_types=1);

namespace App\Tests\Notification\Functional\Command;

use App\Notification\Application\Message\ReconcileNotificationsMessage;
use App\Notification\Application\MessageHandler\ReconcileNotificationsMessageHandler;
use App\Notification\Application\Reconciliation\NotificationReconciliation;
use App\Shared\Infrastructure\Monitoring\JobRun\CurrentJobRun;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class ReconcileNotificationsCommandTest extends KernelTestCase
{
    public function testReconcilesTheLast24HoursByDefault(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('0 outcome(s)', $tester->getDisplay());
    }

    public function testAcceptsAnExplicitRange(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--from' => '-2 days', '--to' => '-1 day']));
    }

    public function testRejectsUnreadableDatesAndTooOldRanges(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--from' => 'not a date']));
        self::assertStringContainsString('Could not read --from or --to', $tester->getDisplay());

        self::assertSame(Command::INVALID, $tester->execute(['--from' => '-30 days']));
        self::assertStringContainsString('may not start before', $tester->getDisplay());

        self::assertSame(Command::INVALID, $tester->execute(['--from' => '-1 day', '--to' => '-2 days']));
    }

    public function testScheduledMessageRunsTheReconciliation(): void
    {
        self::bootKernel();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ReconcileNotificationsMessage());

        $this->addToAssertionCount(1);
    }

    public function testJobSkipsWhileAnotherReconciliationRuns(): void
    {
        self::bootKernel();
        $lock = self::getContainer()->get(LockFactory::class)->createLock('notification-reconciliation');
        self::assertTrue($lock->acquire());

        try {
            $logger = new BufferingLogger();
            $jobRun = new CurrentJobRun();
            $jobRun->begin();
            $handler = new ReconcileNotificationsMessageHandler(
                self::getContainer()->get(NotificationReconciliation::class),
                self::getContainer()->get(LockFactory::class),
                $logger,
                $jobRun,
            );
            $handler(new ReconcileNotificationsMessage());

            self::assertSame(['notification.reconciliation.skipped'], array_column($logger->cleanLogs(), 1));
            self::assertSame('already_running', $jobRun->skipReason());
        } finally {
            $lock->release();
        }
    }

    private function tester(): CommandTester
    {
        $kernel = self::bootKernel();

        return new CommandTester(new Application($kernel)->find('app:notification:reconcile'));
    }
}
