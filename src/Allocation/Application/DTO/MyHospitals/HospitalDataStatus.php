<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

use App\Allocation\Application\Hospital\MyHospitals\MyHospitalsEntry;

/**
 * Data status of one hospital as the viewer may see it. Parts the viewer lacks the permission for are null:
 * `imports` needs IMPORT, `months`, `fullyCoveredMonths` and `gaps` need STATISTICS.
 */
final readonly class HospitalDataStatus
{
    /**
     * @param list<MonthStatus>|null  $months   the twelve months of the window, oldest first
     * @param list<MonthGap>|null     $gaps     possible gaps over the whole history
     * @param list<DataStatusFinding> $findings
     */
    public function __construct(
        public MyHospitalsEntry $entry,
        public int $allocationCount,
        public ?\DateTimeImmutable $firstAllocationAt,
        public ?\DateTimeImmutable $lastAllocationAt,
        public ?HospitalImportSummary $imports,
        public ?array $months,
        public ?int $fullyCoveredMonths,
        public ?array $gaps,
        public array $findings,
    ) {
    }

    public function hasData(): bool
    {
        return $this->allocationCount > 0;
    }

    /**
     * Complete months of the twelve-month view, or null without STATISTICS.
     */
    public function completeMonths(): ?int
    {
        if (null === $this->months) {
            return null;
        }

        return \count(array_filter($this->months, static fn (MonthStatus $month): bool => MonthCoverageState::InProgress !== $month->state));
    }
}
