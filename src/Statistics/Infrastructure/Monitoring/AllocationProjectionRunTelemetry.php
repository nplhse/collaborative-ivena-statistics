<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Monitoring;

use App\Shared\Infrastructure\Monitoring\JobRun\JobRunRecorder;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunThresholdConfig;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunTrace;
use App\Statistics\Application\Contract\AllocationProjectionRunTelemetryInterface;
use App\Statistics\Application\DTO\AllocationStatsProjectionRebuildResult;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Sentry\State\HubInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Records one allocation stats projection rebuild: a job_run row, a Sentry transaction
 * `allocation_projection_rebuild` with spans for change detection, delete, insert, closure request and
 * materialized view refresh, one `projection_rebuild.summary` log and threshold warnings.
 *
 * Only ids, counts and durations leave the system.
 */
final class AllocationProjectionRunTelemetry implements AllocationProjectionRunTelemetryInterface
{
    public const string JOB = 'allocation_projection_rebuild';
    public const string TRANSACTION = 'allocation_projection_rebuild';
    public const string OP = 'projection.allocation_stats';

    private ?JobRunTrace $trace = null;
    private string $runId = '';
    private string $trigger = '';
    private int $importId = 0;
    private ?int $hospitalId = null;
    private ?AllocationStatsProjectionRebuildResult $result = null;
    private bool $materializedViewsRefreshed = false;

    /** @var array<string, int> step => milliseconds */
    private array $stepMs = [];

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly JobRunRecorder $recorder,
        private readonly JobRunThresholdConfig $thresholdConfig,
        private readonly HubInterface $hub,
        private readonly Connection $connection,
        private readonly LoggerInterface $projectionRebuildLogger,
    ) {
    }

    #[\Override]
    public function begin(int $importId, string $trigger): void
    {
        $this->reset();
        $this->runId = Uuid::v7()->toRfc4122();
        $this->trigger = $trigger;
        $this->importId = $importId;
        $this->hospitalId = $this->hospitalOf($importId);

        // Messenger redelivers the message after a dead worker; its running row of this import is abandoned.
        $this->recorder->abandonRunning(self::JOB, $this->runId, importId: $importId);
        $this->recorder->start($this->runId, self::JOB, $trigger, importId: $importId, hospitalId: $this->hospitalId);

        $this->trace = JobRunTrace::start(
            $this->hub,
            self::TRANSACTION,
            self::OP,
            ['trigger' => $trigger],
            array_filter(['run_id' => $this->runId, 'import_id' => $importId, 'hospital_id' => $this->hospitalId], static fn (int|string|null $value): bool => null !== $value),
        );
    }

    #[\Override]
    public function measure(string $op, \Closure $work, array $data = []): mixed
    {
        if (!$this->trace instanceof JobRunTrace) {
            return $work();
        }
        $started = hrtime(true);
        $this->trace->startSpan($op, $data);
        try {
            return $work();
        } finally {
            $this->trace->finishSpan($data);
            $step = str_replace(['projection.', '.'], ['', '_'], $op).'_ms';
            $this->stepMs[$step] = ($this->stepMs[$step] ?? 0) + (int) round((float) (hrtime(true) - $started) / 1e6);
        }
    }

    #[\Override]
    public function rebuilt(AllocationStatsProjectionRebuildResult $result): void
    {
        $this->result = $result;
    }

    #[\Override]
    public function materializedViewsRefreshed(): void
    {
        $this->materializedViewsRefreshed = true;
    }

    #[\Override]
    public function end(?\Throwable $error = null): void
    {
        $trace = $this->trace;
        if (!$trace instanceof JobRunTrace) {
            return;
        }
        $seconds = $trace->seconds();
        $durationMs = $trace->durationMs();
        $peak = $trace->peakMemoryBytes();
        $status = $error instanceof \Throwable ? 'failed' : 'succeeded';
        $exceeded = $this->thresholdConfig->for(self::JOB)->exceeded($seconds, $peak);

        $metrics = [
            ...($this->result instanceof AllocationStatsProjectionRebuildResult ? ['rows_deleted' => $this->result->rowsDeleted, 'rows_written' => $this->result->rowsWritten] : []),
            'mv_refreshed' => $this->materializedViewsRefreshed,
            ...$this->stepMs,
        ];
        $this->recorder->finish($this->runId, self::JOB, $status, $durationMs, $peak, $exceeded, $metrics, $error);

        $this->projectionRebuildLogger->info('projection_rebuild.summary', [
            'run_id' => $this->runId,
            'import_id' => $this->importId,
            'hospital_id' => $this->hospitalId,
            'trigger' => $this->trigger,
            'status' => $status,
            ...$metrics,
            'duration_ms' => $durationMs,
            'peak_memory_bytes' => $peak,
        ]);
        if ([] !== $exceeded) {
            $this->projectionRebuildLogger->warning('projection_rebuild.threshold_exceeded', [
                'run_id' => $this->runId,
                'import_id' => $this->importId,
                'thresholds' => implode(',', $exceeded),
                'duration_ms' => $durationMs,
                'peak_memory_bytes' => $peak,
            ]);
        }
        if ($error instanceof \Throwable) {
            // Only the class leaves the system; the message may quote SQL with data.
            $this->projectionRebuildLogger->error('projection_rebuild.failed', ['run_id' => $this->runId, 'import_id' => $this->importId, 'error_class' => $error::class]);
        }

        $trace->finish(
            $error instanceof \Throwable,
            [
                'trigger' => $this->trigger,
                'status' => $status,
                'threshold_exceeded' => [] === $exceeded ? 'none' : implode(',', $exceeded),
            ],
            [...$metrics, 'peak_memory_bytes' => $peak],
        );
        $this->reset();
    }

    private function hospitalOf(int $importId): ?int
    {
        try {
            $hospitalId = $this->connection->fetchOne('SELECT hospital_id FROM import WHERE id = :id', ['id' => $importId]);
        } catch (\Throwable) {
            return null;
        }

        return is_numeric($hospitalId) ? (int) $hospitalId : null;
    }

    private function reset(): void
    {
        $this->trace = null;
        $this->runId = '';
        $this->trigger = '';
        $this->importId = 0;
        $this->hospitalId = null;
        $this->result = null;
        $this->materializedViewsRefreshed = false;
        $this->stepMs = [];
    }
}
