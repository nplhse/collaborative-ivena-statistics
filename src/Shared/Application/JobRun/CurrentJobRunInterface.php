<?php

declare(strict_types=1);

namespace App\Shared\Application\JobRun;

/**
 * The job_run row of the job a handler is running in (ADR 019). Handlers of recorded jobs add their counters
 * and report runs that had nothing to do. Outside a recorded run, for example when a handler is called
 * directly, both calls do nothing.
 */
interface CurrentJobRunInterface
{
    /**
     * Adds counters to the run's metrics. Ids, counts and short codes only; never names or messages.
     *
     * @param array<string, int|bool|string|null> $metrics
     */
    public function addMetrics(array $metrics): void;

    /**
     * Ends the run as skipped: it ran but had nothing to do, e.g. not due today or another run holds the lock.
     */
    public function markSkipped(string $reason): void;
}
