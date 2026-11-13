<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

/**
 * Consecutive months in the state {@see MonthCoverageState::Missing}.
 */
final readonly class MonthGap
{
    public function __construct(
        public \DateTimeImmutable $firstMonth,
        public \DateTimeImmutable $lastMonth,
        public int $months,
    ) {
    }
}
