<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Monitoring;

use App\Engagement\Application\Message\SendMonthlySubmissionRemindersMessage;
use App\Notification\Application\Message\ReconcileNotificationsMessage;
use App\Shared\Infrastructure\Monitoring\JobRun\CurrentJobRun;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunMiddleware;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunRecorder;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunThresholdConfig;
use App\Shared\Infrastructure\Monitoring\JobRun\RecordedJobsInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Scheduler\RecurringMessage;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class JobRunMiddlewareTest extends KernelTestCase
{
    public function testAScheduledJobHandledByTheWorkerIsRecordedWithItsCounters(): void
    {
        $message = new ReconcileNotificationsMessage();
        $trigger = RecurringMessage::cron('17 * * * *', $message)->getTrigger();
        $scheduled = new ScheduledStamp(new MessageContext('default', 'reconcile', $trigger, new \DateTimeImmutable()));

        $this->bus()->dispatch(new Envelope($message, [new ReceivedStamp('scheduler_default'), $scheduled]));

        $run = $this->onlyRun();
        self::assertSame('notification_reconcile', $run['job']);
        self::assertSame('scheduler', $run['triggered_by']);
        self::assertSame('succeeded', $run['status']);
        self::assertSame(1, (int) $run['attempt']);
        self::assertNotNull($run['finished_at']);
        self::assertGreaterThan(0, (int) $run['peak_memory_bytes']);
        self::assertSame(['deliveries_resumed' => 0, 'published' => 0], $this->metrics($run));
    }

    public function testAReminderRunOutsideTheFirstWorkingDayIsSkipped(): void
    {
        $message = new SendMonthlySubmissionRemindersMessage(new \DateTimeImmutable('2026-10-15 08:00:00', new \DateTimeZone('Europe/Berlin')));

        $this->bus()->dispatch(new Envelope($message, [new ReceivedStamp('async_priority_low')]));

        $run = $this->onlyRun();
        self::assertSame('monthly_reminder', $run['job']);
        self::assertSame('messenger', $run['triggered_by']);
        self::assertSame('skipped', $run['status']);
        self::assertSame(['skip_reason' => 'not_due'], $this->metrics($run));
    }

    public function testAJobHandledWithoutAWorkerIsNotRecorded(): void
    {
        // Test routing handles the message synchronously, so the envelope carries no ReceivedStamp.
        $this->bus()->dispatch(new ReconcileNotificationsMessage());

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM job_run'));
    }

    public function testAFailedRunKeepsTheHandlersExceptionAndTheAttempt(): void
    {
        $clock = new MockClock('2026-10-26 09:00:00', 'UTC');
        $middleware = $this->middleware($clock);

        try {
            $middleware->handle(new Envelope(new \stdClass(), [new ReceivedStamp('async_priority_low'), new RedeliveryStamp(2)]), $this->stack(static function (): never {
                throw new \DomainException('Klinikum Beispiel');
            }));
            self::fail('The handler exception must reach Messenger.');
        } catch (HandlerFailedException) {
        }

        $run = $this->onlyRun();
        self::assertSame('test_job', $run['job']);
        self::assertSame('failed', $run['status']);
        self::assertSame(3, (int) $run['attempt']);
        self::assertSame(\DomainException::class, $run['error_class']);
        self::assertSame('Klinikum Beispiel', $run['error_message']);
    }

    public function testASlowRunIsMarkedAndSkippedRunsKeepTheirReason(): void
    {
        $clock = new MockClock('2026-10-26 09:00:00', 'UTC');
        $currentRun = new CurrentJobRun();
        $handler = static function () use ($clock, $currentRun): void {
            $clock->sleep(2);
            $currentRun->addMetrics(['rows' => 4]);
            $currentRun->markSkipped('already_running');
        };
        $middleware = $this->middleware($clock, $currentRun);

        $middleware->handle(new Envelope(new \stdClass(), [new ReceivedStamp('async_priority_low')]), $this->stack($handler));

        $run = $this->onlyRun();
        self::assertSame('skipped', $run['status']);
        self::assertSame(2000, (int) $run['duration_ms']);
        self::assertSame('slow', $run['thresholds_exceeded']);
        self::assertSame(['rows' => 4, 'skip_reason' => 'already_running'], $this->metrics($run));
        self::assertFalse($currentRun->isActive(), 'The run ends with the handling.');
    }

    public function testAMessageThatIsNotAJobIsNotRecorded(): void
    {
        $middleware = $this->middleware(new MockClock());

        $middleware->handle(new Envelope(new \ArrayObject(), [new ReceivedStamp('async_priority_low')]), $this->stack(static function (): void {
        }, \ArrayObject::class));

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM job_run'));
    }

    private function middleware(MockClock $clock, ?CurrentJobRun $currentRun = null): JobRunMiddleware
    {
        $recordedJobs = new class implements RecordedJobsInterface {
            #[\Override]
            public function recordedJobs(): array
            {
                return [\stdClass::class => 'test_job'];
            }
        };
        $thresholds = new JobRunThresholdConfig(['test_job' => ['slow_seconds' => 1, 'long_seconds' => 10, 'memory_mb' => null, 'overdue_after_minutes' => null]]);

        return new JobRunMiddleware(
            new JobRunRecorder($this->connection(), $clock, new NullLogger()),
            $thresholds,
            $currentRun ?? new CurrentJobRun(),
            $clock,
            [$recordedJobs],
        );
    }

    /**
     * @param class-string $messageClass
     */
    private function stack(\Closure $handler, string $messageClass = \stdClass::class): StackMiddleware
    {
        return new StackMiddleware(new HandleMessageMiddleware(new HandlersLocator([$messageClass => [$handler]])));
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyRun(): array
    {
        $runs = $this->connection()->fetchAllAssociative('SELECT * FROM job_run');
        self::assertCount(1, $runs);

        return $runs[0];
    }

    /**
     * @param array<string, mixed> $run
     *
     * @return array<string, mixed>
     */
    private function metrics(array $run): array
    {
        $metrics = json_decode((string) $run['metrics'], true);
        self::assertIsArray($metrics);
        ksort($metrics);

        return $metrics;
    }

    private function bus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
