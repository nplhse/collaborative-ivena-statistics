<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\JobRun;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes one job_run row per run: at the start as running, at the end with status, duration, memory and counters.
 *
 * Monitoring must not fail the job, for example when the database connection is gone after an error,
 * so every write catches its own errors and only logs them.
 */
final readonly class JobRunRecorder
{
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_ABANDONED = 'abandoned';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Marks running rows of the job as abandoned. Call it only while the job's lock is held,
     * so every other running row belongs to a dead process.
     *
     * @return int the number of abandoned runs
     */
    public function abandonRunning(string $job, string $exceptRunId): int
    {
        $abandoned = 0;
        $this->write($job, $exceptRunId, function () use ($job, $exceptRunId, &$abandoned): void {
            $abandoned = $this->connection->executeStatement(
                'UPDATE job_run SET status = :abandoned, finished_at = :now WHERE job = :job AND status = :running AND id <> :run',
                [
                    'abandoned' => self::STATUS_ABANDONED,
                    'now' => $this->now(),
                    'job' => $job,
                    'running' => self::STATUS_RUNNING,
                    'run' => $exceptRunId,
                ],
            );
        });

        return $abandoned;
    }

    /**
     * @param array<string, int|bool|string|list<int>|null> $metrics
     */
    public function start(string $runId, string $job, string $trigger, array $metrics = []): void
    {
        $this->write($job, $runId, function () use ($runId, $job, $trigger, $metrics): void {
            $this->connection->insert('job_run', [
                'id' => $runId,
                'job' => $job,
                'triggered_by' => $trigger,
                'status' => self::STATUS_RUNNING,
                'started_at' => $this->now(),
                'metrics' => $metrics,
            ], ['metrics' => Types::JSON]);
        });
    }

    /**
     * Replaces the counters of the run with the final ones. The error message stays in the database and is never sent.
     *
     * @param list<string>                                  $thresholdsExceeded
     * @param array<string, int|bool|string|list<int>|null> $metrics
     */
    public function finish(
        string $runId,
        string $job,
        string $status,
        int $durationMs,
        int $peakMemoryBytes,
        array $thresholdsExceeded,
        array $metrics,
        ?\Throwable $error = null,
    ): void {
        $this->write($job, $runId, function () use ($runId, $status, $durationMs, $peakMemoryBytes, $thresholdsExceeded, $metrics, $error): void {
            $this->connection->update('job_run', [
                'status' => $status,
                'finished_at' => $this->now(),
                'duration_ms' => $durationMs,
                'peak_memory_bytes' => $peakMemoryBytes,
                'thresholds_exceeded' => [] === $thresholdsExceeded ? null : implode(',', $thresholdsExceeded),
                'error_class' => $error instanceof \Throwable ? $error::class : null,
                'error_message' => $error instanceof \Throwable ? mb_substr($error->getMessage(), 0, 2000) : null,
                'metrics' => $metrics,
            ], ['id' => $runId], ['metrics' => Types::JSON]);
        });
    }

    private function write(string $job, string $runId, \Closure $write): void
    {
        try {
            $write();
        } catch (\Throwable $exception) {
            $this->logger->warning('job_run.record_failed', ['job' => $job, 'run_id' => $runId, 'error_class' => $exception::class]);
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:sP');
    }
}
