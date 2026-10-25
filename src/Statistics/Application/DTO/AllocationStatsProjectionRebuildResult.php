<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

/**
 * Rows the projection rebuild of one import replaced.
 */
final readonly class AllocationStatsProjectionRebuildResult
{
    public function __construct(
        public int $rowsDeleted,
        public int $rowsWritten,
    ) {
    }
}
