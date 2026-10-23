<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

/**
 * Outcome of a finished batch re-import, counted per item state (ADR 016, batch re-imports).
 * Items that could not be dispatched or were skipped count as failed.
 */
final readonly class ImportBatchSummary
{
    public function __construct(
        public int $batchRunId,
        public ?int $initiatedByUserId,
        public int $completed,
        public int $partial,
        public int $failed,
        public int $superseded,
    ) {
    }

    public function total(): int
    {
        return $this->completed + $this->partial + $this->failed + $this->superseded;
    }
}
