<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

/**
 * Data status of the user's hospitals. Every figure says on how many hospitals it is based: allocations and
 * period over all listed hospitals (VIEW), imports over those with IMPORT, months over those with STATISTICS.
 */
final readonly class MyHospitalsOverview
{
    /**
     * @param list<HospitalDataStatus> $hospitals
     * @param list<OverviewMonth>|null $months    null when no hospital has STATISTICS
     */
    public function __construct(
        public array $hospitals,
        public int $allocationCount,
        public ?\DateTimeImmutable $firstAllocationAt,
        public ?\DateTimeImmutable $lastAllocationAt,
        public ?OverviewImports $imports,
        public ?array $months,
        public int $statisticsHospitals,
    ) {
    }

    public function hospitalCount(): int
    {
        return \count($this->hospitals);
    }

    public function hospitalsWithData(): int
    {
        return \count(array_filter($this->hospitals, static fn (HospitalDataStatus $status): bool => $status->hasData()));
    }

    /**
     * Complete months of the twelve-month view (the current month excluded).
     */
    public function completeMonths(): int
    {
        return \count(array_filter($this->months ?? [], static fn (OverviewMonth $month): bool => !$month->inProgress));
    }

    /**
     * Complete months in which every hospital that delivered data covered every day.
     */
    public function fullyCoveredMonths(): int
    {
        return \count(array_filter(
            $this->months ?? [],
            static fn (OverviewMonth $month): bool => !$month->inProgress && $month->hospitals > 0 && $month->coveredHospitals === $month->hospitals,
        ));
    }
}
