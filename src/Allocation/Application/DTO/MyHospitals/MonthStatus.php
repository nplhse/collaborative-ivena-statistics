<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

final readonly class MonthStatus
{
    /**
     * @param \DateTimeImmutable $month first day of the month, 00:00
     * @param int                $days  calendar days of the month
     */
    public function __construct(
        public \DateTimeImmutable $month,
        public MonthCoverageState $state,
        public int $allocations,
        public int $coveredDays,
        public int $days,
    ) {
    }
}
