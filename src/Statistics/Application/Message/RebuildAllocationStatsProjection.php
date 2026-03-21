<?php

declare(strict_types=1);

namespace App\Statistics\Application\Message;

/**
 * Rebuilds allocation_stats_projection rows for a single import (delete + batch insert).
 */
final readonly class RebuildAllocationStatsProjection
{
    public function __construct(
        public int $importId,
    ) {
    }
}
