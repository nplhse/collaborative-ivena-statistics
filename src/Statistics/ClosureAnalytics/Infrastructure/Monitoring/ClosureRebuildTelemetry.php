<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Monitoring;

use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeClock;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceWindow;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

/**
 * Records one closure rebuild run: a row in closure_rebuild_run, a Sentry transaction with spans
 * per pass, hospital, weekly partition and publish, one summary log and threshold warnings.
 *
 * Only run ids, hospital ids, counts and durations leave the system; no names and no closure periods.
 * The transaction is kept off the Sentry scope so Doctrine query spans stay with the console or worker transaction.
 * Outside begin()/end(), for example when the rebuilder runs on its own in tests, spans and the run row are skipped.
 */
final class ClosureRebuildTelemetry
{
    public const string TRANSACTION = 'closure_rebuild';
    public const string OP = 'closure.rebuild';
    public const int SLOW_SECONDS = 1800;
    public const int LONG_SECONDS = 7200;
    public const int MEMORY_WARNING_BYTES = 200 * 1024 * 1024;

    private ?string $runId = null;
    private string $trigger = '';
    private float $startedAt = 0.0;
    private ?Transaction $transaction = null;

    /** @var list<Span> */
    private array $spans = [];

    /** @var list<int> */
    private array $hospitalIds = [];
    private bool $full = false;
    private int $passes = 0;
    private int $events = 0;
    private int $partitions = 0;
    private int $hoursWritten = 0;
    private ?float $publishSeconds = null;
    private int $requestCount = 0;
    private ?int $queryCount = null;
    private ?string $referenceWindow = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $closureRebuildLogger,
    ) {
    }

    /**
     * Starts a run while the rebuild lock is held. Runs still marked as running belong to dead processes.
     *
     * @param list<int>|null $hospitalIds null for every hospital
     */
    public function begin(string $runId, string $trigger, ?array $hospitalIds): void
    {
        $this->reset();
        $this->runId = $runId;
        $this->trigger = $trigger;
        $this->startedAt = microtime(true);
        $this->full = null === $hospitalIds;
        $this->hospitalIds = $hospitalIds ?? [];
        memory_reset_peak_usage();

        $this->record(function () use ($runId): void {
            $abandoned = $this->connection->executeStatement(
                "UPDATE closure_rebuild_run SET status = 'abandoned', finished_at = :now WHERE status = 'running' AND id <> :run",
                ['now' => $this->now(), 'run' => $runId],
            );
            if ($abandoned > 0) {
                $this->closureRebuildLogger->warning('closure_rebuild.run_abandoned', ['run_id' => $runId, 'runs' => $abandoned]);
            }
            $this->connection->insert('closure_rebuild_run', [
                'id' => $runId,
                'triggered_by' => $this->trigger,
                'status' => 'running',
                'full_rebuild' => $this->full,
                'hospital_ids' => json_encode($this->hospitalIds, \JSON_THROW_ON_ERROR),
                'started_at' => $this->now(),
            ], ['full_rebuild' => ParameterType::BOOLEAN]);
        });

        $context = TransactionContext::make()->setName(self::TRANSACTION)->setOp(self::OP)->setOrigin('manual.closure_rebuild');
        $context->setTags(['trigger' => $trigger, 'scope' => $this->full ? 'full' : 'hospitals']);
        $context->setData(['run_id' => $runId]);
        $this->transaction = $this->hub->startTransaction($context);
        $this->spans = [$this->transaction];
    }

    /**
     * @param array<string, int|bool|list<int>> $data ids and counts only
     */
    public function startSpan(string $op, array $data = []): void
    {
        $parent = [] === $this->spans ? null : $this->spans[array_key_last($this->spans)];
        if (!$parent instanceof Span) {
            return;
        }
        $this->spans[] = $parent->startChild(SpanContext::make()->setOp($op)->setData($data));
    }

    /**
     * @param array<string, int|bool|list<int>> $data ids and counts only
     */
    public function finishSpan(array $data = []): void
    {
        if (\count($this->spans) <= 1) {
            return;
        }
        $span = array_pop($this->spans);
        $span->setData($data);
        $span->finish();
    }

    /**
     * @param list<int> $hospitalIds
     */
    public function startPass(int $pass, array $hospitalIds, int $events): void
    {
        // Each pass span counts, also when the worker loops over newly arrived requests in the same run.
        ++$this->passes;
        $this->events += $events;
        $this->startSpan('closure.rebuild.pass', ['pass' => $pass, 'hospital_ids' => $hospitalIds, 'events' => $events]);
    }

    public function countPartition(): void
    {
        ++$this->partitions;
    }

    /**
     * SQL statements the volume build issued in this run (ADR 015 phase 3 acceptance).
     */
    public function countQueries(int $queries): void
    {
        $this->queryCount = $queries;
    }

    public function countHoursWritten(int $hours): void
    {
        $this->hoursWritten += $hours;
    }

    /**
     * @param list<int> $hospitalIds
     */
    public function published(bool $full, array $hospitalIds, float $seconds): void
    {
        $this->full = $full;
        $this->hospitalIds = $hospitalIds;
        $this->publishSeconds = ($this->publishSeconds ?? 0.0) + $seconds;
    }

    /**
     * Recorded per run so comparison runs with another window stay apart from routine ones.
     */
    public function referenceWindow(ClosureVolumeReferenceWindow $window): void
    {
        $this->referenceWindow = sprintf('%d/%d', $window->weeksBefore, $window->weeksAfter);
    }

    public function claimedRequests(int $count): void
    {
        $this->requestCount += $count;
    }

    public function end(?\Throwable $error = null): void
    {
        if (null === $this->runId) {
            return;
        }
        while (\count($this->spans) > 1) {
            $this->finishSpan();
        }
        $seconds = microtime(true) - $this->startedAt;
        $peak = memory_get_peak_usage(true);
        $status = $error instanceof \Throwable ? 'failed' : 'succeeded';
        $summary = [
            'run_id' => $this->runId,
            'trigger' => $this->trigger,
            'status' => $status,
            'full' => $this->full,
            'hospital_ids' => $this->hospitalIds,
            'passes' => $this->passes,
            'events' => $this->events,
            'partitions' => $this->partitions,
            'hours_written' => $this->hoursWritten,
            'requests' => $this->requestCount,
            'queries' => $this->queryCount,
            'duration_ms' => (int) round($seconds * 1000.0),
            'publish_ms' => null === $this->publishSeconds ? null : (int) round($this->publishSeconds * 1000.0),
            'peak_memory_bytes' => $peak,
        ];

        $runId = $this->runId;
        $this->record(function () use ($runId, $status, $summary, $peak, $error): void {
            $this->connection->update('closure_rebuild_run', [
                'status' => $status,
                'full_rebuild' => $this->full,
                'hospital_ids' => json_encode($this->hospitalIds, \JSON_THROW_ON_ERROR),
                'finished_at' => $this->now(),
                'duration_ms' => $summary['duration_ms'],
                'passes' => $this->passes,
                'events' => $this->events,
                'partitions' => $this->partitions,
                'hours_written' => $this->hoursWritten,
                'publish_ms' => $summary['publish_ms'],
                'peak_memory_bytes' => $peak,
                'request_count' => $this->requestCount,
                'query_count' => $this->queryCount,
                'reference_window' => $this->referenceWindow,
                'error_class' => $error instanceof \Throwable ? $error::class : null,
                'error_message' => $error instanceof \Throwable ? mb_substr($error->getMessage(), 0, 2000) : null,
            ], ['id' => $runId], ['full_rebuild' => ParameterType::BOOLEAN]);
        });

        $this->closureRebuildLogger->info('closure_rebuild.summary', $summary);
        $exceeded = self::exceededThresholds($seconds, $peak, $this->passes);
        if ([] !== $exceeded) {
            $this->closureRebuildLogger->warning('closure_rebuild.threshold_exceeded', [
                'run_id' => $this->runId,
                'thresholds' => implode(',', $exceeded),
                'duration_ms' => $summary['duration_ms'],
                'peak_memory_bytes' => $peak,
                'passes' => $this->passes,
            ]);
        }
        if ($error instanceof \Throwable) {
            // Only the class leaves the system; the message may quote SQL with data.
            $this->closureRebuildLogger->error('closure_rebuild.failed', ['run_id' => $this->runId, 'error_class' => $error::class]);
        }

        if ($this->transaction instanceof Transaction) {
            $this->transaction->setData([
                'hospital_ids' => $this->hospitalIds,
                'passes' => $this->passes,
                'events' => $this->events,
                'partitions' => $this->partitions,
                'hours_written' => $this->hoursWritten,
                'peak_memory_bytes' => $peak,
            ]);
            $this->transaction->setTags([
                'status' => $status,
                'scope' => $this->full ? 'full' : 'hospitals',
                'passes' => (string) $this->passes,
                'threshold_exceeded' => [] === $exceeded ? 'none' : implode(',', $exceeded),
            ]);
            $this->transaction->setStatus($error instanceof \Throwable ? SpanStatus::internalError() : SpanStatus::ok());
            $this->transaction->finish();
        }
        $this->reset();
    }

    /**
     * @return list<string>
     */
    public static function exceededThresholds(float $seconds, int $peakBytes, int $passes): array
    {
        $exceeded = [];
        if ($seconds > self::LONG_SECONDS) {
            $exceeded[] = 'long_run';
        } elseif ($seconds > self::SLOW_SECONDS) {
            $exceeded[] = 'slow';
        }
        if ($peakBytes > self::MEMORY_WARNING_BYTES) {
            $exceeded[] = 'memory';
        }
        if ($passes > 1) {
            $exceeded[] = 'passes';
        }

        return $exceeded;
    }

    /**
     * Monitoring must not fail the rebuild, for example when the database connection is gone after an error.
     */
    private function record(\Closure $write): void
    {
        try {
            $write();
        } catch (\Throwable $exception) {
            $this->closureRebuildLogger->warning('closure_rebuild.run_record_failed', ['run_id' => $this->runId, 'error_class' => $exception::class]);
        }
    }

    private function reset(): void
    {
        $this->runId = null;
        $this->transaction = null;
        $this->spans = [];
        $this->hospitalIds = [];
        $this->full = false;
        $this->passes = 0;
        $this->events = 0;
        $this->partitions = 0;
        $this->hoursWritten = 0;
        $this->publishSeconds = null;
        $this->requestCount = 0;
        $this->queryCount = null;
        $this->referenceWindow = null;
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(ClosureVolumeClock::zone())->format('Y-m-d H:i:s');
    }
}
