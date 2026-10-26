<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Messenger;

use App\Shared\Application\JobRun\CurrentJobRunInterface;
use App\Shared\Application\Monitoring\Message\CleanUpMonitoringMessage;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Keeps the monitoring tables bounded (ADR 019, "Retention"):
 *
 * - hourly rows of messenger_metric older than the hourly retention are folded into daily rows (whole UTC days),
 *   which are kept;
 * - messenger_failure and messenger_queue_sample rows older than their retention are deleted;
 * - job_run rows are deleted after the routine retention when they succeeded or were skipped without an exceeded
 *   threshold, every other row after the longer one.
 *
 * @psalm-suppress UnusedClass Registered via #[AsMessageHandler].
 */
#[AsMessageHandler]
final readonly class CleanUpMonitoringMessageHandler
{
    private const string FOLD = <<<'SQL'
INSERT INTO messenger_metric (granularity, bucket_start, message_class, transport, attempts, handled, handled_after_retry, retried, failed, duration_ms_sum, duration_ms_max, last_activity_at)
SELECT 'day', date_trunc('day', bucket_start AT TIME ZONE 'UTC') AT TIME ZONE 'UTC', message_class, transport,
       SUM(attempts), SUM(handled), SUM(handled_after_retry), SUM(retried), SUM(failed), SUM(duration_ms_sum),
       MAX(duration_ms_max), MAX(last_activity_at)
FROM messenger_metric
WHERE granularity = 'hour' AND bucket_start < :cutoff
GROUP BY 2, 3, 4
ON CONFLICT (granularity, bucket_start, message_class, transport) DO UPDATE SET
    attempts = messenger_metric.attempts + EXCLUDED.attempts,
    handled = messenger_metric.handled + EXCLUDED.handled,
    handled_after_retry = messenger_metric.handled_after_retry + EXCLUDED.handled_after_retry,
    retried = messenger_metric.retried + EXCLUDED.retried,
    failed = messenger_metric.failed + EXCLUDED.failed,
    duration_ms_sum = messenger_metric.duration_ms_sum + EXCLUDED.duration_ms_sum,
    duration_ms_max = GREATEST(messenger_metric.duration_ms_max, EXCLUDED.duration_ms_max),
    last_activity_at = GREATEST(messenger_metric.last_activity_at, EXCLUDED.last_activity_at)
SQL;

    /**
     * @param array{hourly_days: int, failure_days: int, queue_sample_days: int} $retention
     * @param array{routine_days: int, other_days: int}                          $jobRunRetention
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private CurrentJobRunInterface $jobRun,
        #[Autowire(param: 'app.messenger_monitoring.retention')]
        private array $retention,
        #[Autowire(param: 'app.job_runs_retention')]
        private array $jobRunRetention,
    ) {
    }

    /** @psalm-suppress UnusedParam The message type selects the handler; it carries no data. */
    public function __invoke(CleanUpMonitoringMessage $message): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $hourlyCutoff = $now->modify(\sprintf('-%d days', $this->retention['hourly_days']))->setTime(0, 0);

        $metrics = $this->connection->transactional(function (Connection $connection) use ($hourlyCutoff): array {
            $cutoff = ['cutoff' => $this->format($hourlyCutoff)];

            return [
                'folded_daily_rows' => $connection->executeStatement(self::FOLD, $cutoff),
                'deleted_hourly_rows' => $connection->executeStatement("DELETE FROM messenger_metric WHERE granularity = 'hour' AND bucket_start < :cutoff", $cutoff),
            ];
        });

        $metrics['deleted_failures'] = $this->connection->executeStatement(
            'DELETE FROM messenger_failure WHERE occurred_at < :cutoff',
            ['cutoff' => $this->daysAgo($now, $this->retention['failure_days'])],
        );
        $metrics['deleted_queue_samples'] = $this->connection->executeStatement(
            'DELETE FROM messenger_queue_sample WHERE sampled_at < :cutoff',
            ['cutoff' => $this->daysAgo($now, $this->retention['queue_sample_days'])],
        );
        $metrics['deleted_job_runs'] = $this->connection->executeStatement(
            "DELETE FROM job_run WHERE status IN ('succeeded', 'skipped') AND thresholds_exceeded IS NULL AND started_at < :cutoff",
            ['cutoff' => $this->daysAgo($now, $this->jobRunRetention['routine_days'])],
        ) + $this->connection->executeStatement(
            'DELETE FROM job_run WHERE started_at < :cutoff',
            ['cutoff' => $this->daysAgo($now, $this->jobRunRetention['other_days'])],
        );

        $this->jobRun->addMetrics($metrics);
    }

    private function daysAgo(\DateTimeImmutable $now, int $days): string
    {
        return $this->format($now->modify(\sprintf('-%d days', $days)));
    }

    private function format(\DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:sP');
    }
}
