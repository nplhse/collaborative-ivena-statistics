<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

/**
 * State of the imports in one batch, for the progress line on the import list.
 *
 * @psalm-suppress PossiblyUnusedProperty Consumed by the import list template.
 */
final readonly class ImportBatchProgress
{
    public function __construct(
        public int $batchRunId,
        public int $total,
        public int $succeeded,
        public int $failed,
        public int $superseded,
        public bool $finished,
    ) {
    }

    public function open(): int
    {
        return max(0, $this->total - $this->succeeded - $this->failed - $this->superseded);
    }
}
