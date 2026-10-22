<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

/**
 * Result of deleting an import's result rows, needed for the follow-up work after commit.
 */
final readonly class ImportDataRemoval
{
    /**
     * @param array{projection: int, rejects: int, allocations: int, closure_intervals: int, assessments: int, mci_cases: int} $counts
     */
    public function __construct(
        public array $counts,
        public bool $needsMaterializedViewRefresh,
    ) {
    }
}
