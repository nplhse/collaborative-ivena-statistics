<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Health;

use App\Shared\Application\Health\HealthCheckContributorInterface;
use App\Shared\Application\Health\HealthCheckResult;
use App\Shared\Application\Health\HealthCheckStatus;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunThresholdConfig;
use Psr\Clock\ClockInterface;

/**
 * Scheduler jobs (ADR 019): every job with an expected interval (app.job_runs.<job>.overdue_after_minutes) must have
 * finished a run within it. Overdue is degraded, overdue by more than twice the interval unhealthy; a job whose last
 * run failed is degraded. A job that never ran counts from the start of the run log, so a new job is not overdue
 * right after its deploy.
 *
 * @psalm-suppress UnusedClass Tagged via HealthCheckContributorInterface.
 */
final readonly class ScheduledJobsHealthCheck implements HealthCheckContributorInterface
{
    public const string KEY = 'scheduled_jobs';

    private const int LISTED = 3;

    public function __construct(
        private MessengerHealthQuery $query,
        private JobRunThresholdConfig $config,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function check(): HealthCheckResult
    {
        $intervals = $this->config->overdueIntervals();
        if ([] === $intervals) {
            return new HealthCheckResult(self::KEY, HealthCheckStatus::Healthy, 'ok');
        }

        $now = $this->clock->now();
        $runs = $this->query->lastFinishedRuns(array_keys($intervals));
        $runLogSince = $this->query->runLogSince();

        $status = HealthCheckStatus::Healthy;
        $overdue = [];
        $failed = [];
        foreach ($intervals as $job => $minutes) {
            $run = $runs[$job] ?? null;
            $since = $run['finishedAt'] ?? $runLogSince;
            $age = null === $since ? 0 : intdiv(max(0, $now->getTimestamp() - $since->getTimestamp()), 60);

            if ($age > 2 * $minutes) {
                $status = $status->worst(HealthCheckStatus::Unhealthy);
                $overdue[] = \sprintf('%s (%s)', $job, $this->age($age));
            } elseif ($age > $minutes) {
                $status = $status->worst(HealthCheckStatus::Degraded);
                $overdue[] = \sprintf('%s (%s)', $job, $this->age($age));
            }

            if ('failed' === ($run['status'] ?? null)) {
                $status = $status->worst(HealthCheckStatus::Degraded);
                $failed[] = $job;
            }
        }

        $parts = [];
        if ([] !== $overdue) {
            $parts[] = 'overdue: '.$this->listed($overdue);
        }
        if ([] !== $failed) {
            $parts[] = 'last run failed: '.$this->listed($failed);
        }

        return new HealthCheckResult(self::KEY, $status, [] === $parts ? 'ok' : implode('; ', $parts));
    }

    /**
     * At most three entries, so the value stays short on the dashboard; the job run list has the rest.
     *
     * @param list<string> $entries
     */
    private function listed(array $entries): string
    {
        $shown = implode(', ', \array_slice($entries, 0, self::LISTED));

        return \count($entries) > self::LISTED ? \sprintf('%s +%d', $shown, \count($entries) - self::LISTED) : $shown;
    }

    private function age(int $minutes): string
    {
        return $minutes >= 120 ? \sprintf('%d h', intdiv($minutes, 60)) : \sprintf('%d min', $minutes);
    }
}
