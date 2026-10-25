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
    /** The console rebuild of every import: one run, compared and limited apart from the rebuilds per import. */
    public const string JOB_FULL = 'allocation_projection_full_rebuild';
    public const string TRANSACTION = 'allocation_projection_rebuild';
    public const string OP = 'projection.allocation_stats';

    private ?JobRunTrace $trace = null;
    private string $runId = '';
    private string $job = self::JOB;
    private string $trigger = '';
    private ?int $importId = null;
    private ?int $hospitalId = null;
    private int $imports = 0;
    private int $rowsDeleted = 0;
    private int $rowsWritten = 0;
    private bool $materializedViewsRefreshed = false;

    /** @var array<string, int> step => milliseconds */
    private array $stepMs = [];

    /** Open measure() calls; nested steps get summed phase spans instead of one span per call. */
    private int $depth = 0;

    /** @var array<string, int> nested op => nanoseconds within the open top-level step */
    private array $nestedNs = [];

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
    public function begin(?int $importId, string $trigger): void
    {
        $this->reset();
        $this->runId = Uuid::v7()->toRfc4122();
        $this->job = null === $importId ? self::JOB_FULL : self::JOB;
        $this->trigger = $trigger;
        $this->importId = $importId;
        $this->hospitalId = null === $importId ? null : $this->hospitalOf($importId);

        // A running row of the same import (Messenger redelivered the message) or of an earlier full rebuild
        // belongs to a process that died.
        $this->recorder->abandonRunning($this->job, $this->runId, importId: $importId);
        $this->recorder->start($this->runId, $this->job, $trigger, importId: $importId, hospitalId: $this->hospitalId);

        $this->trace = JobRunTrace::start(
            $this->hub,
            self::TRANSACTION,
            self::OP,
            ['trigger' => $trigger, 'scope' => null === $importId ? 'full' : 'import'],
            array_filter(['run_id' => $this->runId, 'import_id' => $importId, 'hospital_id' => $this->hospitalId], static fn (int|string|null $value): bool => null !== $value),
        );
    }

    #[\Override]
    public function measure(string $op, \Closure $work, array $data = []): mixed
    {
        $trace = $this->trace;
        if (!$trace instanceof JobRunTrace) {
            return $work();
        }
        $started = hrtime(true);
        ++$this->depth;
        if (1 === $this->depth) {
            $this->nestedNs = [];
            $trace->startSpan($op, $data);
        }
        try {
            return $work();
        } finally {
            $elapsed = hrtime(true) - $started;
            $step = str_replace(['projection.', '.'], ['', '_'], $op).'_ms';
            $this->stepMs[$step] = ($this->stepMs[$step] ?? 0) + (int) round((float) $elapsed / 1e6);
            if (1 === $this->depth) {
                $this->finishTopLevelStep($trace, $data);
            } else {
                $this->nestedNs[$op] = ($this->nestedNs[$op] ?? 0) + $elapsed;
            }
            --$this->depth;
        }
    }

    #[\Override]
    public function rebuilt(AllocationStatsProjectionRebuildResult $result): void
    {
        ++$this->imports;
        $this->rowsDeleted += $result->rowsDeleted;
        $this->rowsWritten += $result->rowsWritten;
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
        $exceeded = $this->thresholdConfig->for($this->job)->exceeded($seconds, $peak);

        $metrics = [
            ...(0 === $this->imports ? [] : ['imports' => $this->imports, 'rows_deleted' => $this->rowsDeleted, 'rows_written' => $this->rowsWritten]),
            'mv_refreshed' => $this->materializedViewsRefreshed,
            ...$this->stepMs,
        ];
        $this->recorder->finish($this->runId, $this->job, $status, $durationMs, $peak, $exceeded, $metrics, $error);

        $this->projectionRebuildLogger->info('projection_rebuild.summary', [
            'run_id' => $this->runId,
            'job' => $this->job,
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
                'scope' => null === $this->importId ? 'full' : 'import',
                'status' => $status,
                'threshold_exceeded' => [] === $exceeded ? 'none' : implode(',', $exceeded),
            ],
            [...$metrics, 'peak_memory_bytes' => $peak],
        );
        $this->reset();
    }

    /**
     * @param array<string, int|bool> $data
     */
    private function finishTopLevelStep(JobRunTrace $trace, array $data): void
    {
        $phases = [];
        foreach ($this->nestedNs as $op => $nanoseconds) {
            $phases[$op] = ['seconds' => (float) $nanoseconds / 1e9, 'data' => ['ms' => (int) round((float) $nanoseconds / 1e6)]];
        }
        if ([] !== $phases) {
            $trace->addPhaseSpans($phases);
        }
        $trace->finishSpan($data);
        $this->nestedNs = [];
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
        $this->job = self::JOB;
        $this->trigger = '';
        $this->importId = null;
        $this->hospitalId = null;
        $this->imports = 0;
        $this->rowsDeleted = 0;
        $this->rowsWritten = 0;
        $this->materializedViewsRefreshed = false;
        $this->stepMs = [];
        $this->depth = 0;
        $this->nestedNs = [];
    }
}
