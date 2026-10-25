<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

/**
 * Outcome of one background bulk deletion, announced to the user who started it.
 */
final readonly class ImportBulkDeletionSummary
{
    public function __construct(
        public string $operationId,
        public int $initiatedByUserId,
        public int $deleted,
        public int $skipped,
        public int $failed,
    ) {
    }

    public function total(): int
    {
        return $this->deleted + $this->skipped + $this->failed;
    }
}
