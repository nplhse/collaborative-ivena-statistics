<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

/**
 * Raw data status of one hospital as read from the database and cached. It holds nothing that depends on the
 * viewer's permissions or on the current date; those are applied when the overview is built.
 *
 * Only allocation imports count. "Relevant" imports are completed or partial ones: only they contribute
 * allocations and covered periods.
 */
final readonly class HospitalDataSnapshot
{
    /**
     * @param array<string, int>               $monthlyVolumes allocations per month ('Y-m'), months without allocations are absent
     * @param list<CoveredPeriod>              $coveredPeriods one per relevant import with a known period
     * @param array<int, UploaderContribution> $uploaders      user id => relevant imports uploaded by that user
     * @param list<ImportIssue>                $importIssues   failed, cancelled, partial and day-gap imports, oldest first
     */
    public function __construct(
        public int $hospitalId,
        public int $allocationCount,
        public ?\DateTimeImmutable $firstAllocationAt,
        public ?\DateTimeImmutable $lastAllocationAt,
        public array $monthlyVolumes,
        public array $coveredPeriods,
        public int $relevantImports,
        public int $partialImports,
        public int $failedImports,
        public int $cancelledImports,
        public int $pendingImports,
        public ?\DateTimeImmutable $lastUploadAt,
        public array $uploaders,
        public array $importIssues,
    ) {
    }
}
