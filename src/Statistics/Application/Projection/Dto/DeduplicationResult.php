<?php

declare(strict_types=1);

namespace App\Statistics\Application\Projection\Dto;

final readonly class DeduplicationResult
{
    public function __construct(
        public DeduplicationReport $report,
        public int $deletedProjections,
        public int $deletedAllocations,
        public int $deletedAssessments,
        public int $deletedOrphanProjections,
    ) {
    }

    public function totalDeletedRows(): int
    {
        return $this->deletedProjections
            + $this->deletedAllocations
            + $this->deletedAssessments
            + $this->deletedOrphanProjections;
    }
}
