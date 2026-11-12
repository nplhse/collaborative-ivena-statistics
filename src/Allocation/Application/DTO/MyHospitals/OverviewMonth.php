<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

/**
 * One month of the aggregate view over the hospitals with STATISTICS permission.
 */
final readonly class OverviewMonth
{
    /**
     * @param int          $hospitals         hospitals that delivered data around this month (state other than outside)
     * @param int          $coveredHospitals  hospitals with every day of the month covered
     * @param list<string> $hospitalsWithGaps names of hospitals with a missing or partial month
     */
    public function __construct(
        public \DateTimeImmutable $month,
        public int $allocations,
        public int $hospitals,
        public int $coveredHospitals,
        public array $hospitalsWithGaps,
        public bool $inProgress,
    ) {
    }
}
