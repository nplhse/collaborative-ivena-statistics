<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

/**
 * @psalm-type RebuildRequestRow = array{id: int, kind: string, hospital_id: ?int, requested_at: string, claimed_at: ?string, claimed_by: ?string, claim_attempts: int, failed_at: ?string, last_error: ?string}
 */
interface ClosureRebuildRequestInterface
{
    public function requestAnalysis(?int $hospitalId): void;

    public function requestVolume(?int $hospitalId): void;

    /**
     * Requests that are not consumed yet: open, claimed by a run, or failed.
     *
     * @return list<RebuildRequestRow>
     */
    public function unconsumed(?int $hospitalId): array;

    /**
     * Returns failed requests to the queue with a fresh attempt budget and schedules a rebuild.
     *
     * @return int number of requests re-queued
     */
    public function retryFailed(?int $hospitalId): int;
}
