<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Monitoring;

use App\Shared\Application\Monitoring\Message\CleanUpMonitoringMessage;
use App\Shared\Application\Monitoring\Message\SampleMessengerQueuesMessage;
use App\Shared\Infrastructure\Monitoring\JobRun\CurrentJobRun;
use App\Shared\Infrastructure\Monitoring\Messenger\CleanUpMonitoringMessageHandler;
use App\Shared\Infrastructure\Monitoring\Messenger\SampleMessengerQueuesMessageHandler;
use App\Shared\Infrastructure\Scheduler\MonitoringScheduleContribution;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class MonitoringJobsTest extends KernelTestCase
{
    private const string CLASS_A = 'App\Example\Message\A';

    public function testTheQueueSampleCountsReadyWaitingAndFailedMessagesOncePerHour(): void
    {
        $clock = new MockClock('2026-10-26 09:20:00', 'UTC');
        $this->queued('low', '2026-10-26 09:05:00', '2026-10-26 09:05:00');
        $this->queued('high', '2026-10-26 09:15:00', '2026-10-26 09:15:00');
        $this->queued('mail', '2026-10-26 09:18:00', '2026-10-26 09:45:00');
        $this->queued('failed', '2026-10-25 12:00:00', '2026-10-25 12:00:00');
        $jobRun = new CurrentJobRun();
        $jobRun->begin();
        $handler = new SampleMessengerQueuesMessageHandler($this->connection(), $clock, $jobRun);

        $handler(new SampleMessengerQueuesMessage());
        $this->queued('low', '2026-10-26 09:25:00', '2026-10-26 09:25:00');
        $clock->sleep(600);
        $handler(new SampleMessengerQueuesMessage());

        $samples = $this->connection()->fetchAllAssociative("SELECT to_char(sampled_at AT TIME ZONE 'UTC', 'HH24:MI') AS at, pending, waiting, failed, oldest_pending_age_seconds FROM messenger_queue_sample");
        self::assertCount(1, $samples, 'a second sample in the same hour replaces the first');
        self::assertSame(['09:00', 3, 1, 1, 1500], [$samples[0]['at'], (int) $samples[0]['pending'], (int) $samples[0]['waiting'], (int) $samples[0]['failed'], (int) $samples[0]['oldest_pending_age_seconds']]);
        self::assertSame(['pending' => 3, 'waiting' => 1, 'failed' => 1, 'oldest_pending_age_seconds' => 1500], $jobRun->metrics());
    }

    public function testAnEmptyQueueIsSampledAsZero(): void
    {
        $handler = new SampleMessengerQueuesMessageHandler($this->connection(), new MockClock('2026-10-26 09:20:00', 'UTC'), new CurrentJobRun());

        $handler(new SampleMessengerQueuesMessage());

        self::assertSame(
            ['pending' => 0, 'waiting' => 0, 'failed' => 0, 'oldest_pending_age_seconds' => null],
            $this->connection()->fetchAssociative('SELECT pending, waiting, failed, oldest_pending_age_seconds FROM messenger_queue_sample'),
        );
    }

    public function testRetentionFoldsOldHoursIntoDaysAndDeletesExpiredRows(): void
    {
        // 90 days back from 2026-10-26 10:00 UTC is 2026-07-28; whole days before it are folded.
        $this->metric('2026-07-27 08:00:00', attempts: 2, handled: 2, durationSum: 100, durationMax: 80);
        $this->metric('2026-07-27 21:00:00', attempts: 3, handled: 2, failed: 1, durationSum: 300, durationMax: 200);
        $this->metric('2026-07-28 01:00:00', attempts: 1, handled: 1, durationSum: 10, durationMax: 10);
        $this->connection()->executeStatement("INSERT INTO messenger_metric (granularity, bucket_start, message_class, transport, attempts, handled, last_activity_at) VALUES ('day', '2026-07-27 00:00:00+00', :class, 'async_priority_low', 1, 1, '2026-07-27 05:00:00+00')", ['class' => self::CLASS_A]);
        $this->failure('2026-09-25 09:00:00');
        $this->failure('2026-09-27 09:00:00');
        $this->connection()->executeStatement("INSERT INTO messenger_queue_sample (sampled_at, pending, waiting, failed) VALUES ('2026-07-27 09:00:00+00', 1, 0, 0), ('2026-07-29 09:00:00+00', 2, 0, 0)");
        $this->jobRun('routine-old', 'succeeded', '2026-04-28 09:00:00', null);
        $this->jobRun('routine-new', 'skipped', '2026-04-30 09:00:00', null);
        $this->jobRun('slow-old', 'succeeded', '2026-04-28 09:00:00', 'slow');
        $this->jobRun('failed-old', 'failed', '2024-10-25 09:00:00', null);
        $this->jobRun('failed-new', 'failed', '2024-10-27 09:00:00', null);
        $jobRun = new CurrentJobRun();
        $jobRun->begin();

        $this->retention($jobRun)(new CleanUpMonitoringMessage());

        self::assertSame(
            [
                ['day', '2026-07-27 00:00', 6, 5, 1, 400, 200, '2026-07-27 21:00'],
                ['hour', '2026-07-28 01:00', 1, 1, 0, 10, 10, '2026-07-28 01:00'],
            ],
            array_map(static fn (array $row): array => [$row['granularity'], $row['bucket'], (int) $row['attempts'], (int) $row['handled'], (int) $row['failed'], (int) $row['duration_ms_sum'], (int) $row['duration_ms_max'], $row['last_activity']], $this->connection()->fetchAllAssociative(
                "SELECT granularity, to_char(bucket_start AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS bucket, attempts, handled, failed, duration_ms_sum, duration_ms_max, to_char(last_activity_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS last_activity FROM messenger_metric ORDER BY bucket_start",
            )),
            'two hours of the 27th are added to the existing daily row; the 28th stays hourly',
        );
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM messenger_failure'), 'failures are kept 30 days');
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM messenger_queue_sample'), 'samples are kept 90 days');
        self::assertSame(['failed-new', 'routine-new', 'slow-old'], $this->connection()->fetchFirstColumn('SELECT id FROM job_run ORDER BY id'));
        self::assertSame(
            ['deleted_failures' => 1, 'deleted_hourly_rows' => 2, 'deleted_job_runs' => 2, 'deleted_queue_samples' => 1, 'folded_daily_rows' => 1],
            $this->sorted($jobRun->metrics()),
        );
    }

    public function testBothJobsAreScheduledAndRecorded(): void
    {
        $contribution = new MonitoringScheduleContribution();

        self::assertSame(
            [SampleMessengerQueuesMessage::class => 'messenger_queue_sample', CleanUpMonitoringMessage::class => 'monitoring_retention'],
            $contribution->recordedJobs(),
        );
        self::assertSame(
            ['5 * * * *', '50 3 * * *'],
            array_map(static fn (\Symfony\Component\Scheduler\RecurringMessage $recurring): string => (string) $recurring->getTrigger(), $contribution->recurringMessages()),
        );
    }

    private function retention(CurrentJobRun $jobRun): CleanUpMonitoringMessageHandler
    {
        return new CleanUpMonitoringMessageHandler(
            $this->connection(),
            new MockClock('2026-10-26 10:00:00', 'UTC'),
            $jobRun,
            ['hourly_days' => 90, 'failure_days' => 30, 'queue_sample_days' => 90],
            ['routine_days' => 180, 'other_days' => 730],
        );
    }

    private function metric(string $hour, int $attempts, int $handled, int $failed = 0, int $durationSum = 0, int $durationMax = 0): void
    {
        $this->connection()->insert('messenger_metric', [
            'granularity' => 'hour',
            'bucket_start' => $hour.'+00:00',
            'message_class' => self::CLASS_A,
            'transport' => 'async_priority_low',
            'attempts' => $attempts,
            'handled' => $handled,
            'failed' => $failed,
            'duration_ms_sum' => $durationSum,
            'duration_ms_max' => $durationMax,
            'last_activity_at' => $hour.'+00:00',
        ]);
    }

    private function failure(string $at): void
    {
        $this->connection()->insert('messenger_failure', ['occurred_at' => $at.'+00:00', 'message_class' => self::CLASS_A, 'transport' => 'async_priority_low', 'attempt' => 1, 'will_retry' => false, 'error_class' => \RuntimeException::class], ['will_retry' => 'boolean']);
    }

    private function jobRun(string $id, string $status, string $startedAt, ?string $thresholds): void
    {
        $this->connection()->insert('job_run', ['id' => $id, 'job' => 'test_job', 'triggered_by' => 'scheduler', 'status' => $status, 'started_at' => $startedAt.'+00:00', 'thresholds_exceeded' => $thresholds]);
    }

    private function queued(string $queue, string $createdAt, string $availableAt): void
    {
        $this->connection()->insert('messenger_messages', ['body' => 'x', 'headers' => '{}', 'queue_name' => $queue, 'created_at' => $createdAt, 'available_at' => $availableAt]);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
