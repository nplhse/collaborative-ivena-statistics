<?php

declare(strict_types=1);

namespace App\Tests\Admin\Integration\Service;

use App\Admin\Application\DTO\Messenger\MessengerMessageTypeRowDto;
use App\Admin\Application\DTO\Messenger\MessengerTransportRowDto;
use App\Admin\Application\Service\Messenger\MessengerMonitoringPeriod;
use App\Admin\Application\Service\Messenger\MessengerMonitoringService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The admin Messenger page (ADR 019) from messenger_metric, messenger_failure, messenger_queue_sample and the live
 * queues; the page itself is rendered in MessengerMonitoringControllerTest.
 *
 * Fixture, now = 2026-11-13 12:30 UTC, so the 24-hour period starts at 2026-11-12 12:00 UTC (full hour):
 *
 * | table    | at (UTC)         | message / queue          | values                                                     |
 * |----------|------------------|--------------------------|------------------------------------------------------------|
 * | metric   | 2026-11-13 10:00 | import, high             | 5 attempts, 3 handled (1 after retry), 1 retried, 1 failed, 5000 ms |
 * | metric   | 2026-11-13 09:00 | mail, mail               | 10 attempts, 10 handled, 1000 ms                           |
 * | metric   | 2026-11-10 09:00 | mail, mail               | 4 attempts, 4 handled, 400 ms (only in 7 and 30 days)      |
 * | failure  | 2026-11-13 10:30 | import, Deadlock         | will retry                                                 |
 * | failure  | 2026-11-13 11:00 | import, Deadlock         | final                                                      |
 * | queue    | 2026-11-13 12:10 | high                     | ready                                                      |
 * | queue    | 2026-11-13 12:25 | mail                     | available at 12:40: waits for a retry                      |
 * | queue    | 2026-11-12 12:30 | failed                   | failure queue                                              |
 * | sample   | 2026-11-13 11:00 | -                        | 7 pending                                                  |
 */
#[ResetDatabase]
final class MessengerMonitoringServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private const string IMPORT = \App\Import\Application\Message\ImportAllocationsMessage::class;
    private const string MAIL = \Symfony\Component\Mailer\Messenger\SendEmailMessage::class;
    private const string DEADLOCK = \Doctrine\DBAL\Exception\DeadlockException::class;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-11-13 12:30:00 UTC');

        $this->metric(self::IMPORT, 'async_priority_high', '2026-11-13 10:00', attempts: 5, handled: 3, handledAfterRetry: 1, retried: 1, failed: 1, durationSum: 5000, durationMax: 2500);
        $this->metric(self::MAIL, 'async_mail', '2026-11-13 09:00', attempts: 10, handled: 10, durationSum: 1000, durationMax: 300);
        $this->metric(self::MAIL, 'async_mail', '2026-11-10 09:00', attempts: 4, handled: 4, durationSum: 400, durationMax: 100);
        $this->failure(self::IMPORT, self::DEADLOCK, '2026-11-13 10:30', willRetry: true);
        $this->failure(self::IMPORT, self::DEADLOCK, '2026-11-13 11:00', willRetry: false);
        $this->queued('high', '2026-11-13 12:10', '2026-11-13 12:10');
        $this->queued('mail', '2026-11-13 12:25', '2026-11-13 12:40');
        $this->queued('failed', '2026-11-12 12:30', '2026-11-12 12:30');
        $this->connection()->executeStatement("INSERT INTO messenger_queue_sample (sampled_at, pending, waiting, failed) VALUES ('2026-11-13 11:00:00+00:00', 7, 0, 1)");
    }

    public function testTheTotalsSumTheHourlyRowsSinceTheStartOfThePeriod(): void
    {
        $report = $this->service()->report(MessengerMonitoringPeriod::Day);

        self::assertSame([15, 13, 1, 1, 1, 6000, 2500], [
            $report->totals->attempts,
            $report->totals->handled,
            $report->totals->handledAfterRetry,
            $report->totals->retried,
            $report->totals->failed,
            $report->totals->durationMsSum,
            $report->totals->durationMsMax,
        ]);
    }

    public function testALongerPeriodIncludesOlderRows(): void
    {
        $report = $this->service()->report(MessengerMonitoringPeriod::Week);

        self::assertSame(17, $report->totals->handled);
    }

    public function testOnlyReadyMessagesOutsideTheFailureQueueArePending(): void
    {
        $report = $this->service()->report(MessengerMonitoringPeriod::Day);

        self::assertSame([1, 1, 1], [$report->pending, $report->waitingForRetry, $report->failedQueue]);
    }

    public function testTheLastActivityIsTheLatestHandlingAttempt(): void
    {
        $report = $this->service()->report(MessengerMonitoringPeriod::Day);

        self::assertEquals(new \DateTimeImmutable('2026-11-13 10:15:00 UTC'), $report->lastActivityAt);
    }

    public function testEveryConfiguredTransportIsListedWithItsQueueAndCounters(): void
    {
        $report = $this->service()->report(MessengerMonitoringPeriod::Day);

        self::assertSame(
            ['async_priority_high', 'async_priority_low', 'async_mail', 'scheduler_default', 'failed'],
            array_map(static fn (MessengerTransportRowDto $row): string => $row->name, $report->transports),
        );
        $high = $report->transports[0];
        self::assertSame(['high', 1, 0, 3], [$high->queueName, $high->pending, $high->waitingForRetry, $high->counts->handled]);
        self::assertEquals(new \DateTimeImmutable('2026-11-13 12:10:00 UTC'), $high->oldestPendingAt);
    }

    public function testTheSchedulerHasNoQueueToMeasure(): void
    {
        $report = $this->service()->report(MessengerMonitoringPeriod::Day);

        $scheduler = $report->transports[3];
        self::assertSame(['scheduler_default', null, null, null], [$scheduler->name, $scheduler->queueName, $scheduler->pending, $scheduler->waitingForRetry]);
    }

    public function testATransportThatIsNotConfiguredIsListedAfterTheOthersWhenItHandledMessages(): void
    {
        $this->metric(self::MAIL, 'sync', '2026-11-13 11:00', attempts: 2, handled: 2);

        $report = $this->service()->report(MessengerMonitoringPeriod::Day);

        $last = $report->transports[\count($report->transports) - 1];
        self::assertSame(['sync', null, 2], [$last->name, $last->queueName, $last->counts->handled]);
    }

    public function testMessageTypesAreListedWithMostAttemptsFirst(): void
    {
        $report = $this->service()->report(MessengerMonitoringPeriod::Day);

        self::assertSame(
            [[self::MAIL, ['async_mail'], 10], [self::IMPORT, ['async_priority_high'], 5]],
            array_map(static fn (MessengerMessageTypeRowDto $row): array => [$row->messageClass, $row->transports, $row->counts->attempts], $report->messageTypes),
        );
    }

    public function testFailuresAreGroupedByMessageAndErrorClass(): void
    {
        $report = $this->service()->report(MessengerMonitoringPeriod::Day);

        self::assertCount(1, $report->failures);
        $failure = $report->failures[0];
        self::assertSame([self::IMPORT, self::DEADLOCK, 2, 1, 1], [$failure->messageClass, $failure->errorClass, $failure->attempts, $failure->retried, $failure->final]);
        self::assertEquals(new \DateTimeImmutable('2026-11-13 11:00:00 UTC'), $failure->lastOccurredAt);
    }

    public function testTheHourlyHistoryHasOnePointPerHourFromTheStartOfThePeriod(): void
    {
        $history = $this->service()->report(MessengerMonitoringPeriod::Day)->history;

        self::assertSame('hour', $history->granularity);
        self::assertCount(25, $history->buckets, '2026-11-12 12:00 up to 2026-11-13 12:00');
        self::assertEquals(new \DateTimeImmutable('2026-11-12 12:00:00 UTC'), $history->buckets[0]);
        self::assertSame([10, 3], [$history->handled[21], $history->handled[22]], '09:00 and 10:00');
        self::assertSame([5000 / 5, 1000 / 10], [$history->averageDurationMs[22], $history->averageDurationMs[21]]);
        self::assertSame([23 => 7], array_filter($history->backlog, static fn (?int $value): bool => null !== $value), 'one sample at 11:00');
    }

    public function testTheMonthlyHistoryHasOnePointPerDayInBerlin(): void
    {
        $history = $this->service()->report(MessengerMonitoringPeriod::Month)->history;

        self::assertSame('day', $history->granularity);
        self::assertCount(31, $history->buckets, '2026-10-14 up to 2026-11-13');
        self::assertEquals(new \DateTimeImmutable('2026-10-14 00:00:00', new \DateTimeZone('Europe/Berlin')), $history->buckets[0]);
        self::assertSame([4, 13], [$history->handled[27], $history->handled[30]], 'the days of 2026-11-10 and 2026-11-13, after the change to winter time');
    }

    public function testTheLiveQueuesAreTheQueueStateRightNow(): void
    {
        $queues = $this->service()->liveQueues();

        self::assertSame(['pending' => 0, 'waiting' => 1], ['pending' => $queues['mail']['pending'], 'waiting' => $queues['mail']['waiting']]);
        self::assertArrayNotHasKey('low', $queues);
    }

    private function service(): MessengerMonitoringService
    {
        return self::getContainer()->get(MessengerMonitoringService::class);
    }

    private function metric(string $class, string $transport, string $bucketStart, int $attempts, int $handled, int $handledAfterRetry = 0, int $retried = 0, int $failed = 0, int $durationSum = 0, int $durationMax = 0): void
    {
        $bucket = new \DateTimeImmutable($bucketStart, new \DateTimeZone('UTC'));
        $this->connection()->insert('messenger_metric', [
            'granularity' => 'hour',
            'bucket_start' => $bucket->format('Y-m-d H:i:sP'),
            'message_class' => $class,
            'transport' => $transport,
            'attempts' => $attempts,
            'handled' => $handled,
            'handled_after_retry' => $handledAfterRetry,
            'retried' => $retried,
            'failed' => $failed,
            'duration_ms_sum' => $durationSum,
            'duration_ms_max' => $durationMax,
            'last_activity_at' => $bucket->modify('+15 minutes')->format('Y-m-d H:i:sP'),
        ]);
    }

    private function failure(string $class, string $errorClass, string $occurredAt, bool $willRetry): void
    {
        $this->connection()->insert('messenger_failure', [
            'occurred_at' => $occurredAt.':00+00:00',
            'message_class' => $class,
            'transport' => 'async_priority_high',
            'attempt' => 1,
            'will_retry' => $willRetry,
            'error_class' => $errorClass,
        ], ['will_retry' => 'boolean']);
    }

    /** Doctrine stores the queue's times in UTC without a zone. */
    private function queued(string $queue, string $createdAt, string $availableAt): void
    {
        $this->connection()->insert('messenger_messages', [
            'body' => 'O:8:"stdClass":0:{}',
            'headers' => '{}',
            'queue_name' => $queue,
            'created_at' => $createdAt.':00',
            'available_at' => $availableAt.':00',
        ]);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
