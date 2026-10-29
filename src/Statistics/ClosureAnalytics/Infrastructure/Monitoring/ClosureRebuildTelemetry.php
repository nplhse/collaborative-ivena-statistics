<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Monitoring;

use App\Shared\Infrastructure\Monitoring\JobRun\JobRunRecorder;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunThresholdConfig;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunThresholds;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunTrace;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceWindow;
use Psr\Log\LoggerInterface;
use Sentry\State\HubInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Records one closure rebuild run: a job_run row, a Sentry transaction with spans per pass, hospital,
 * weekly partition and publish, one summary log and threshold warnings.
 *
 * Only run ids, hospital ids, counts and durations leave the system; no names and no closure periods.
 * Outside begin()/end(), for example when the rebuilder runs on its own in tests, spans and the run row are skipped.
 */
final class ClosureRebuildTelemetry
{
    public const string JOB = 'closure_rebuild';
    public const string TRANSACTION = 'closure_rebuild';
    public const string OP = 'closure.rebuild';

    private ?string $runId = null;
    private string $trigger = '';
    private ?JobRunTrace $trace = null;

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
        private readonly JobRunRecorder $recorder,
        private readonly JobRunThresholdConfig $thresholdConfig,
        private readonly HubInterface $hub,
        #[Target('closure_rebuild')]
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
        $this->full = null === $hospitalIds;
        $this->hospitalIds = $hospitalIds ?? [];

        $abandoned = $this->recorder->abandonRunning(self::JOB, $runId);
        if ($abandoned > 0) {
            $this->closureRebuildLogger->warning('closure_rebuild.run_abandoned', ['run_id' => $runId, 'runs' => $abandoned]);
        }
        $this->recorder->start($runId, self::JOB, $trigger, ['full' => $this->full, 'hospital_ids' => $this->hospitalIds]);

        $this->trace = JobRunTrace::start(
            $this->hub,
            self::TRANSACTION,
            self::OP,
            ['trigger' => $trigger, 'scope' => $this->full ? 'full' : 'hospitals'],
            ['run_id' => $runId],
        );
    }

    /**
     * @param array<string, int|bool|list<int>> $data ids and counts only
     */
    public function startSpan(string $op, array $data = []): void
    {
        $this->trace?->startSpan($op, $data);
    }

    /**
     * @param array<string, int|bool|list<int>> $data ids and counts only
     */
    public function finishSpan(array $data = []): void
    {
        $this->trace?->finishSpan($data);
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
        if (null === $this->runId || !$this->trace instanceof JobRunTrace) {
            return;
        }
        $trace = $this->trace;
        $seconds = $trace->seconds();
        $peak = $trace->peakMemoryBytes();
        $durationMs = $trace->durationMs();
        $status = $error instanceof \Throwable ? 'failed' : 'succeeded';
        $publishMs = null === $this->publishSeconds ? null : (int) round($this->publishSeconds * 1000.0);
        $exceeded = self::exceededThresholds($this->thresholdConfig->for(self::JOB), $seconds, $peak, $this->passes);

        $this->recorder->finish($this->runId, self::JOB, $status, $durationMs, $peak, $exceeded, [
            'full' => $this->full,
            'hospital_ids' => $this->hospitalIds,
            'passes' => $this->passes,
            'events' => $this->events,
            'partitions' => $this->partitions,
            'hours_written' => $this->hoursWritten,
            'publish_ms' => $publishMs,
            'requests' => $this->requestCount,
            'queries' => $this->queryCount,
            'reference_window' => $this->referenceWindow,
        ], $error);

        $this->closureRebuildLogger->info('closure_rebuild.summary', [
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
            'duration_ms' => $durationMs,
            'publish_ms' => $publishMs,
            'peak_memory_bytes' => $peak,
        ]);
        if ([] !== $exceeded) {
            $this->closureRebuildLogger->warning('closure_rebuild.threshold_exceeded', [
                'run_id' => $this->runId,
                'thresholds' => implode(',', $exceeded),
                'duration_ms' => $durationMs,
                'peak_memory_bytes' => $peak,
                'passes' => $this->passes,
            ]);
        }
        if ($error instanceof \Throwable) {
            // Only the class leaves the system; the message may quote SQL with data.
            $this->closureRebuildLogger->error('closure_rebuild.failed', ['run_id' => $this->runId, 'error_class' => $error::class]);
        }

        $trace->finish(
            $error instanceof \Throwable,
            [
                'status' => $status,
                'scope' => $this->full ? 'full' : 'hospitals',
                'passes' => (string) $this->passes,
                'threshold_exceeded' => [] === $exceeded ? 'none' : implode(',', $exceeded),
            ],
            [
                'hospital_ids' => $this->hospitalIds,
                'passes' => $this->passes,
                'events' => $this->events,
                'partitions' => $this->partitions,
                'hours_written' => $this->hoursWritten,
                'peak_memory_bytes' => $peak,
            ],
        );
        $this->reset();
    }

    /**
     * Duration and memory from app.job_runs.closure_rebuild, plus a second pass within one run.
     *
     * @return list<string>
     */
    public static function exceededThresholds(JobRunThresholds $thresholds, float $seconds, int $peakBytes, int $passes): array
    {
        $exceeded = $thresholds->exceeded($seconds, $peakBytes);
        if ($passes > 1) {
            $exceeded[] = 'passes';
        }

        return $exceeded;
    }

    private function reset(): void
    {
        $this->runId = null;
        $this->trace = null;
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
}
