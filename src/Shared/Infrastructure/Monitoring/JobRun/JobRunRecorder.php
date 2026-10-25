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
     * Marks running rows of the job as abandoned; they belong to a dead process.
     * Without an import run, call it only while the job's lock is held, so every other running row of the job is dead.
     * Jobs without a lock pass what they run for (import run or import): a running row of the same import run or
     * import belongs to an earlier attempt whose worker died.
     *
     * @return int the number of abandoned runs
     */
    public function abandonRunning(string $job, string $exceptRunId, ?string $importRunId = null, ?int $importId = null): int
    {
        $abandoned = 0;
        $this->write($job, $exceptRunId, function () use ($job, $exceptRunId, $importRunId, $importId, &$abandoned): void {
            $sql = 'UPDATE job_run SET status = :abandoned, finished_at = :now WHERE job = :job AND status = :running AND id <> :run';
            $params = [
                'abandoned' => self::STATUS_ABANDONED,
                'now' => $this->now(),
                'job' => $job,
                'running' => self::STATUS_RUNNING,
                'run' => $exceptRunId,
            ];
            if (null !== $importRunId) {
                $sql .= ' AND import_run_id = :importRun';
                $params['importRun'] = $importRunId;
            }
            if (null !== $importId) {
                $sql .= ' AND import_id = :import';
                $params['import'] = $importId;
            }
            $abandoned = $this->connection->executeStatement($sql, $params);
        });

        return $abandoned;
    }

    /**
     * Sets the status of the last attempt of an import run when it still has the expected one,
     * for example a retrying attempt after Messenger gave up on the message.
     *
     * @return array{id: string, attempt: int}|null the changed attempt, null when none matched or the write failed
     */
    public function changeLastAttemptStatus(string $job, string $importRunId, string $from, string $to): ?array
    {
        $changed = null;
        $this->write($job, $importRunId, function () use ($job, $importRunId, $from, $to, &$changed): void {
            $row = $this->connection->fetchAssociative(
                <<<'SQL'
UPDATE job_run SET status = :to
WHERE id = (
    SELECT id FROM job_run WHERE job = :job AND import_run_id = :importRun ORDER BY attempt DESC, started_at DESC LIMIT 1
) AND status = :from
RETURNING id, attempt
SQL,
                ['to' => $to, 'job' => $job, 'importRun' => $importRunId, 'from' => $from],
            );
            if (\is_array($row)) {
                $changed = ['id' => (string) $row['id'], 'attempt' => (int) $row['attempt']];
            }
        });

        return $changed;
    }

    /**
     * Earlier attempts of an import run, so a Messenger retry gets the next attempt number. 0 when the read fails.
     */
    public function attemptsOf(string $job, string $importRunId): int
    {
        $attempts = 0;
        $this->write($job, $importRunId, function () use ($job, $importRunId, &$attempts): void {
            $attempts = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM job_run WHERE job = :job AND import_run_id = :importRun',
                ['job' => $job, 'importRun' => $importRunId],
            );
        });

        return $attempts;
    }

    /**
     * @param array<string, int|bool|string|list<int>|null> $metrics
     */
    public function start(
        string $runId,
        string $job,
        string $trigger,
        array $metrics = [],
        int $attempt = 1,
        ?int $importId = null,
        ?string $importRunId = null,
        ?int $hospitalId = null,
    ): void {
        $this->write($job, $runId, function () use ($runId, $job, $trigger, $metrics, $attempt, $importId, $importRunId, $hospitalId): void {
            $this->connection->insert('job_run', [
                'id' => $runId,
                'job' => $job,
                'triggered_by' => $trigger,
                'status' => self::STATUS_RUNNING,
                'attempt' => $attempt,
                'import_id' => $importId,
                'import_run_id' => $importRunId,
                'hospital_id' => $hospitalId,
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
