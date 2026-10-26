<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Monitoring;

use App\Shared\Application\Health\HealthCheckContributorInterface;
use App\Shared\Application\Health\HealthCheckService;
use App\Shared\Application\Health\HealthCheckStatus;
use App\Shared\Infrastructure\Monitoring\Health\MessengerBacklogHealthCheck;
use App\Shared\Infrastructure\Monitoring\Health\MessengerHealthQuery;
use App\Shared\Infrastructure\Monitoring\Health\MessengerHealthSettings;
use App\Shared\Infrastructure\Monitoring\Health\MessengerRetryHealthCheck;
use App\Shared\Infrastructure\Monitoring\Health\MessengerStalledHealthCheck;
use App\Shared\Infrastructure\Monitoring\Health\ScheduledJobsHealthCheck;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunThresholdConfig;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class MessengerHealthChecksTest extends KernelTestCase
{
    private const string NOW = '2026-10-26 12:00:00';

    public function testTheBacklogFollowsTheOldestReadyMessage(): void
    {
        self::assertSame([HealthCheckStatus::Healthy, 'ok'], $this->outcome($this->backlog()), 'an empty queue is healthy');

        $this->queued('low', '-20 minutes');
        $this->queued('mail', '-3 hours', availableAt: '+5 minutes');
        $this->queued('failed', '-2 days');
        self::assertSame([HealthCheckStatus::Healthy, 'ok'], $this->outcome($this->backlog()), 'delayed messages and the failure queue do not count');

        $this->queued('high', '-45 minutes');
        self::assertSame([HealthCheckStatus::Degraded, 'oldest message waits 45 min (high)'], $this->outcome($this->backlog()));

        $this->queued('low', '-3 hours');
        self::assertSame([HealthCheckStatus::Unhealthy, 'oldest message waits 180 min (low)'], $this->outcome($this->backlog()));
    }

    public function testProcessingIsStalledOnlyWithOldReadyMessagesAndNoRecentAttempt(): void
    {
        self::assertSame([HealthCheckStatus::Healthy, 'ok'], $this->outcome($this->stalled()), 'an idle worker with an empty queue is fine');

        $this->queued('low', '-10 minutes');
        self::assertSame([HealthCheckStatus::Healthy, 'ok'], $this->outcome($this->stalled()), 'a message younger than the limit is not stalled yet');

        $this->queued('low', '-40 minutes');
        $this->queued('high', '-20 minutes');
        self::assertSame([HealthCheckStatus::Unhealthy, '3 message(s) waiting, no handling recorded'], $this->outcome($this->stalled()));

        $this->activity('-30 minutes');
        self::assertSame([HealthCheckStatus::Unhealthy, '3 message(s) waiting, no handling for 30 min'], $this->outcome($this->stalled()));

        $this->activity('-2 minutes');
        self::assertSame([HealthCheckStatus::Healthy, 'ok'], $this->outcome($this->stalled()), 'a worker that is busy is not stalled');
    }

    public function testTheRetryRateNeedsEnoughAttempts(): void
    {
        $this->metric('-2 hours', attempts: 10, retried: 5);
        self::assertSame([HealthCheckStatus::Healthy, 'ok'], $this->outcome($this->retries()), 'ten attempts are too few to judge');

        $this->metric('-30 hours', attempts: 100, retried: 50);
        self::assertSame([HealthCheckStatus::Healthy, 'ok'], $this->outcome($this->retries()), 'only the last 24 hours count');

        $this->metric('-5 hours', attempts: 30, retried: 1);
        self::assertSame([HealthCheckStatus::Degraded, 'retry rate 15.0 % (6 of 40 attempts in 24 h)'], $this->outcome($this->retries()));
    }

    public function testScheduledJobsAreOverdueAfterTheirIntervalAndUnhealthyAfterTwice(): void
    {
        $config = new JobRunThresholdConfig([
            'hourly_job' => ['slow_seconds' => null, 'long_seconds' => null, 'memory_mb' => null, 'overdue_after_minutes' => 120],
            'daily_job' => ['slow_seconds' => null, 'long_seconds' => null, 'memory_mb' => null, 'overdue_after_minutes' => 1500],
            'no_interval' => ['slow_seconds' => 10, 'long_seconds' => 20, 'memory_mb' => null],
        ]);

        self::assertSame([HealthCheckStatus::Healthy, 'ok'], $this->outcome($this->jobs($config)), 'no run log yet: nothing is overdue');

        $this->finishedRun('hourly_job', 'succeeded', '-30 minutes');
        $this->finishedRun('no_interval', 'failed', '-30 days');
        self::assertSame([HealthCheckStatus::Unhealthy, 'overdue: daily_job (720 h)'], $this->outcome($this->jobs($config)), 'a job that never ran counts from the start of the run log');

        $this->finishedRun('daily_job', 'skipped', '-26 hours');
        self::assertSame([HealthCheckStatus::Degraded, 'overdue: daily_job (26 h)'], $this->outcome($this->jobs($config)), 'a skipped run proves the job ran');

        $this->finishedRun('daily_job', 'failed', '-1 hour');
        self::assertSame([HealthCheckStatus::Degraded, 'last run failed: daily_job'], $this->outcome($this->jobs($config)));
    }

    public function testEnoughAttemptsWithFewRetriesAreHealthy(): void
    {
        $this->metric('-1 hour', attempts: 30, retried: 3);

        self::assertSame([HealthCheckStatus::Healthy, 'ok'], $this->outcome($this->retries()), '10 % is still within the limit');
    }

    public function testWithoutAnyJobIntervalTheJobsAreNotChecked(): void
    {
        $this->finishedRun('some_job', 'failed', '-10 days');

        self::assertSame([HealthCheckStatus::Healthy, 'ok'], $this->outcome($this->jobs(new JobRunThresholdConfig([
            'some_job' => ['slow_seconds' => 10, 'long_seconds' => 20, 'memory_mb' => null],
        ]))));
        self::assertSame([], $this->query()->lastFinishedRuns([]));
    }

    public function testLongJobListsAreShortened(): void
    {
        $intervals = [];
        foreach (['a_job', 'b_job', 'c_job', 'd_job', 'e_job'] as $job) {
            $intervals[$job] = ['slow_seconds' => null, 'long_seconds' => null, 'memory_mb' => null, 'overdue_after_minutes' => 60];
        }
        $this->finishedRun('other', 'succeeded', '-90 minutes');

        self::assertSame(
            [HealthCheckStatus::Degraded, 'overdue: a_job (91 min), b_job (91 min), c_job (91 min) +2'],
            $this->outcome($this->jobs(new JobRunThresholdConfig($intervals))),
        );
    }

    public function testTheChecksAreWiredAndKeepTheirStatusInTheReport(): void
    {
        $this->queued('low', '-3 hours');

        $report = self::getContainer()->get(HealthCheckService::class)->check();

        self::assertSame(HealthCheckStatus::Unhealthy, $report->status);
        self::assertSame(HealthCheckStatus::Unhealthy, $report->statusOf('messenger_backlog'));
        self::assertSame(HealthCheckStatus::Unhealthy, $report->statusOf('messenger_stalled'));
        self::assertSame(HealthCheckStatus::Healthy, $report->statusOf('messenger_retries'));
        self::assertArrayHasKey('scheduled_jobs', $report->checks);
        self::assertSame(['database' => 'ok', 'storage' => $report->checks['storage']], $report->toPublicArray()['checks'], 'the Messenger checks stay private');
    }

    /**
     * @return array{HealthCheckStatus, string}
     */
    private function outcome(HealthCheckContributorInterface $check): array
    {
        $result = $check->check();

        return [$result->status, $result->value];
    }

    private function backlog(): MessengerBacklogHealthCheck
    {
        return new MessengerBacklogHealthCheck($this->query(), $this->settings(), $this->clock());
    }

    private function stalled(): MessengerStalledHealthCheck
    {
        return new MessengerStalledHealthCheck($this->query(), $this->settings(), $this->clock());
    }

    private function retries(): MessengerRetryHealthCheck
    {
        return new MessengerRetryHealthCheck($this->query(), $this->settings(), $this->clock());
    }

    private function jobs(JobRunThresholdConfig $config): ScheduledJobsHealthCheck
    {
        return new ScheduledJobsHealthCheck($this->query(), $config, $this->clock());
    }

    private function settings(): MessengerHealthSettings
    {
        return new MessengerHealthSettings([
            'backlog_warning_minutes' => 30,
            'backlog_critical_minutes' => 120,
            'stalled_after_minutes' => 15,
            'retry_rate_warning_percent' => 10,
            'retry_min_attempts' => 20,
        ]);
    }

    private function clock(): MockClock
    {
        return new MockClock(self::NOW, 'UTC');
    }

    private function at(string $relative): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'))->modify($relative);
    }

    private function queued(string $queue, string $createdAt, ?string $availableAt = null): void
    {
        $this->connection()->insert('messenger_messages', [
            'body' => 'x',
            'headers' => '{}',
            'queue_name' => $queue,
            'created_at' => $this->at($createdAt)->format('Y-m-d H:i:s'),
            'available_at' => $this->at($availableAt ?? $createdAt)->format('Y-m-d H:i:s'),
        ]);
    }

    private function activity(string $at): void
    {
        $this->metric($at, attempts: 1, retried: 0);
    }

    private function metric(string $at, int $attempts, int $retried): void
    {
        $time = $this->at($at);
        $this->connection()->executeStatement(
            <<<'SQL'
INSERT INTO messenger_metric (granularity, bucket_start, message_class, transport, attempts, handled, retried, last_activity_at)
VALUES ('hour', :bucket, 'App\Example', 'async_priority_low', :attempts, :handled, :retried, :at)
ON CONFLICT (granularity, bucket_start, message_class, transport) DO UPDATE SET
    attempts = messenger_metric.attempts + EXCLUDED.attempts, retried = messenger_metric.retried + EXCLUDED.retried,
    last_activity_at = GREATEST(messenger_metric.last_activity_at, EXCLUDED.last_activity_at)
SQL,
            ['bucket' => $time->format('Y-m-d H:00:00+00:00'), 'attempts' => $attempts, 'handled' => $attempts - $retried, 'retried' => $retried, 'at' => $time->format('Y-m-d H:i:sP')],
        );
    }

    private function finishedRun(string $job, string $status, string $finishedAt): void
    {
        $finished = $this->at($finishedAt);
        $this->connection()->insert('job_run', [
            'id' => $job.'-'.$finished->getTimestamp(),
            'job' => $job,
            'triggered_by' => 'scheduler',
            'status' => $status,
            'started_at' => $finished->modify('-1 minute')->format('Y-m-d H:i:sP'),
            'finished_at' => $finished->format('Y-m-d H:i:sP'),
        ]);
    }

    private function query(): MessengerHealthQuery
    {
        return new MessengerHealthQuery($this->connection());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
