<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Monitoring;

use App\Import\Application\Contracts\ImportRunTelemetryInterface;
use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Application\Contracts\RowReaderInterface;
use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\DTO\ImportSummary;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunRecorder;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunThresholdConfig;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunTrace;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Sentry\State\HubInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Uid\Uuid;

/**
 * Records one attempt of an allocation or closure import run: a job_run row, a Sentry transaction `import_run`
 * with spans for cleanup, hospital profile, rows (read, map and persist as summed phase spans), deduplication
 * and finish, one `import.run.summary` log and threshold warnings.
 *
 * Only ids, counts and durations leave the system; no file names, hospital names or row content.
 */
final class ImportRunTelemetry implements ImportRunTelemetryInterface
{
    public const string TRANSACTION = 'import_run';
    public const string OP_ALLOCATIONS = 'import.allocations';
    public const string OP_CLOSURES = 'import.closures';
    public const string JOB_ALLOCATIONS = 'allocation_import';
    public const string JOB_CLOSURES = 'closure_import';

    private ?JobRunTrace $trace = null;
    private string $runId = '';
    private string $job = '';
    private string $trigger = '';
    private string $importRunId = '';
    private ?int $importId = null;
    private ?int $hospitalId = null;
    private int $attempt = 1;

    private ?int $rowsStartedNs = null;
    private ?int $rowsNs = null;
    private int $readNs = 0;
    private int $persistNs = 0;
    private int $chunks = 0;

    /** @var array<string, int> */
    private array $counters = [];

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly JobRunRecorder $recorder,
        private readonly JobRunThresholdConfig $thresholdConfig,
        private readonly HubInterface $hub,
        private readonly Connection $connection,
        #[Target('import')]
        private readonly LoggerInterface $importLogger,
    ) {
    }

    #[\Override]
    public function begin(ImportRunContext $context, Import $import): void
    {
        $this->reset();
        $closures = ImportType::CLOSURE === $import->getType();
        $this->job = $closures ? self::JOB_CLOSURES : self::JOB_ALLOCATIONS;
        $this->runId = Uuid::v7()->toRfc4122();
        $this->importRunId = $context->runId->toRfc4122();
        $this->importId = $context->importId;
        $this->hospitalId = $import->getHospital()?->getId();
        $this->trigger = $this->triggerOf($this->importRunId);

        $this->recorder->abandonRunning($this->job, $this->runId, $this->importRunId);
        $this->attempt = $this->recorder->attemptsOf($this->job, $this->importRunId) + 1;
        $this->recorder->start(
            $this->runId,
            $this->job,
            $this->trigger,
            attempt: $this->attempt,
            importId: $this->importId,
            importRunId: $this->importRunId,
            hospitalId: $this->hospitalId,
        );

        $this->trace = JobRunTrace::start(
            $this->hub,
            self::TRANSACTION,
            $closures ? self::OP_CLOSURES : self::OP_ALLOCATIONS,
            ['job' => $this->job, 'trigger' => $this->trigger, 'attempt' => (string) $this->attempt],
            array_filter(['run_id' => $this->runId, 'import_id' => $this->importId, 'hospital_id' => $this->hospitalId], static fn (int|string|null $value): bool => null !== $value),
        );
    }

    #[\Override]
    public function startSpan(string $op, array $data = []): void
    {
        $this->trace?->startSpan($op, $data);
    }

    #[\Override]
    public function finishSpan(array $data = []): void
    {
        $this->trace?->finishSpan($data);
    }

    #[\Override]
    public function timedReader(RowReaderInterface $reader): RowReaderInterface
    {
        return $this->trace instanceof JobRunTrace ? new TimedRowReader($reader, $this) : $reader;
    }

    #[\Override]
    public function timedRejectWriter(RejectWriterInterface $writer): RejectWriterInterface
    {
        return $this->trace instanceof JobRunTrace ? new TimedRejectWriter($writer, $this) : $writer;
    }

    #[\Override]
    public function startRows(): void
    {
        if (!$this->trace instanceof JobRunTrace) {
            return;
        }
        $this->readNs = 0;
        $this->persistNs = 0;
        $this->chunks = 0;
        $this->rowsNs = null;
        $this->rowsStartedNs = hrtime(true);
        $this->trace->startSpan('import.rows');
    }

    #[\Override]
    public function rowsImported(ImportSummary $summary): void
    {
        if (!$this->trace instanceof JobRunTrace || null === $this->rowsStartedNs) {
            return;
        }
        $this->rowsNs = hrtime(true) - $this->rowsStartedNs;
        $this->trace->addPhaseSpans([
            'import.read' => ['seconds' => (float) $this->readNs / 1e9, 'data' => ['ms' => $this->ms($this->readNs), 'rows' => $summary->total]],
            'import.map' => ['seconds' => (float) $this->mapNs() / 1e9, 'data' => ['ms' => $this->ms($this->mapNs()), 'rows' => $summary->total]],
            'import.persist' => ['seconds' => (float) $this->persistNs / 1e9, 'data' => ['ms' => $this->ms($this->persistNs), 'chunks' => $this->chunks]],
        ]);
        $this->trace->finishSpan(['rows' => $summary->total, 'ok' => $summary->ok, 'rejected' => $summary->rejected, 'chunks' => $this->chunks]);
    }

    #[\Override]
    public function evaluated(Import $import): void
    {
        if (!$this->trace instanceof JobRunTrace) {
            return;
        }
        $this->counters = array_filter([
            'rows_total' => $import->getRowCount(),
            'rows_ok' => $import->getRowsPassed(),
            'rows_rejected' => $import->getRowsRejected(),
            'rows_deduplicated' => self::JOB_ALLOCATIONS === $this->job ? $import->getRowsDeduplicated() : null,
        ], static fn (?int $value): bool => null !== $value);
    }

    #[\Override]
    public function end(string $status, ?\Throwable $error = null): void
    {
        $trace = $this->trace;
        if (!$trace instanceof JobRunTrace) {
            return;
        }
        if (null === $this->rowsNs && null !== $this->rowsStartedNs) {
            // The loop ended with an error: its time still splits into the phases measured so far.
            $this->rowsNs = hrtime(true) - $this->rowsStartedNs;
        }
        $seconds = $trace->seconds();
        $durationMs = $trace->durationMs();
        $peak = $trace->peakMemoryBytes();
        $exceeded = $this->thresholdConfig->for($this->job)->exceeded($seconds, $peak);
        if ($this->attempt > 1) {
            $exceeded[] = 'attempts';
        }

        $metrics = [
            ...$this->counters,
            'chunks' => $this->chunks,
            'read_ms' => $this->ms($this->readNs),
            'map_ms' => $this->ms($this->mapNs()),
            'persist_ms' => $this->ms($this->persistNs),
        ];
        $this->recorder->finish($this->runId, $this->job, $status, $durationMs, $peak, $exceeded, $metrics, $error);

        $this->importLogger->info('import.run.summary', [
            'run_id' => $this->runId,
            'import_run_id' => $this->importRunId,
            'import_id' => $this->importId,
            'hospital_id' => $this->hospitalId,
            'job' => $this->job,
            'trigger' => $this->trigger,
            'status' => $status,
            'attempt' => $this->attempt,
            ...$metrics,
            'duration_ms' => $durationMs,
            'peak_memory_bytes' => $peak,
            'error_class' => $error instanceof \Throwable ? $error::class : null,
        ]);
        if ([] !== $exceeded) {
            $this->importLogger->warning('import.run.threshold_exceeded', [
                'run_id' => $this->runId,
                'import_id' => $this->importId,
                'job' => $this->job,
                'thresholds' => implode(',', $exceeded),
                'attempt' => $this->attempt,
                'duration_ms' => $durationMs,
                'peak_memory_bytes' => $peak,
            ]);
        }

        $trace->finish(
            !\in_array($status, ['completed', 'partial', 'superseded'], true),
            [
                'job' => $this->job,
                'trigger' => $this->trigger,
                'status' => $status,
                'attempt' => (string) $this->attempt,
                'threshold_exceeded' => [] === $exceeded ? 'none' : implode(',', $exceeded),
            ],
            [...$metrics, 'peak_memory_bytes' => $peak],
        );
        $this->reset();
    }

    /**
     * Messenger gave up on the run's message and the lifecycle ended the run as failed: its last attempt,
     * recorded as retrying, becomes failed too. The Sentry transaction of that attempt is already sent,
     * so `import.run.retries_exhausted` is the signal for alerts.
     */
    public function retriesExhausted(int $importId, string $importRunId, ImportType $type): void
    {
        $job = ImportType::CLOSURE === $type ? self::JOB_CLOSURES : self::JOB_ALLOCATIONS;
        $attempt = $this->recorder->changeLastAttemptStatus($job, $importRunId, self::STATUS_RETRYING, 'failed');
        if (null === $attempt) {
            return;
        }

        $this->importLogger->warning('import.run.retries_exhausted', [
            'run_id' => $attempt['id'],
            'import_run_id' => $importRunId,
            'import_id' => $importId,
            'job' => $job,
            'attempt' => $attempt['attempt'],
        ]);
    }

    /**
     * Called by TimedRowReader with the time spent advancing the reader.
     */
    public function addReadTime(int $nanoseconds): void
    {
        $this->readNs += $nanoseconds;
    }

    /**
     * Called by the persisters for each flushed chunk and by TimedRejectWriter for rejected rows.
     */
    public function addPersistTime(int $nanoseconds, bool $chunk): void
    {
        if (!$this->trace instanceof JobRunTrace) {
            return;
        }
        $this->persistNs += $nanoseconds;
        if ($chunk) {
            ++$this->chunks;
        }
    }

    /** Loop time that is neither reading nor persisting: row type detection, mapping and validation. */
    private function mapNs(): int
    {
        return max(0, ($this->rowsNs ?? 0) - $this->readNs - $this->persistNs);
    }

    private function triggerOf(string $importRunId): string
    {
        try {
            $trigger = $this->connection->fetchOne('SELECT "trigger" FROM import_run WHERE id = :id', ['id' => $importRunId]);
        } catch (\Throwable) {
            $trigger = false;
        }

        return \is_string($trigger) ? $trigger : 'unknown';
    }

    private function ms(int $nanoseconds): int
    {
        return (int) round((float) $nanoseconds / 1e6);
    }

    private function reset(): void
    {
        $this->trace = null;
        $this->runId = '';
        $this->job = '';
        $this->trigger = '';
        $this->importRunId = '';
        $this->importId = null;
        $this->hospitalId = null;
        $this->attempt = 1;
        $this->rowsStartedNs = null;
        $this->rowsNs = null;
        $this->readNs = 0;
        $this->persistNs = 0;
        $this->chunks = 0;
        $this->counters = [];
    }
}
