<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

use App\Statistics\Application\DTO\AllocationStatsProjectionRebuildResult;

/**
 * Records one allocation stats projection rebuild of an import (ADR 018): a job_run row,
 * a Sentry transaction with one span per step and one summary log.
 * Outside begin()/end() measure() only runs the work, so the rebuilder also works without a traced run.
 */
interface AllocationProjectionRunTelemetryInterface
{
    /**
     * @param int|null $importId null for the rebuild of every import (job allocation_projection_full_rebuild)
     */
    public function begin(?int $importId, string $trigger): void;

    /**
     * Runs one step of the rebuild in its own span and records its duration as `<step>_ms`.
     * Steps inside another step (delete and insert of each import in a full rebuild) are summed into one
     * phase span per op below it, so a rebuild of many imports stays within Sentry's span limit.
     *
     * @template T
     *
     * @param \Closure(): T           $work
     * @param array<string, int|bool> $data ids and counts only
     *
     * @return T
     */
    public function measure(string $op, \Closure $work, array $data = []): mixed;

    public function rebuilt(AllocationStatsProjectionRebuildResult $result): void;

    public function materializedViewsRefreshed(): void;

    public function end(?\Throwable $error = null): void;
}
