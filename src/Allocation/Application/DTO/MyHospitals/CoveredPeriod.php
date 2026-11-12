<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

/**
 * Days covered by one allocation import, first and last day inclusive (time of day is 00:00).
 */
final readonly class CoveredPeriod
{
    public function __construct(
        public \DateTimeImmutable $firstDay,
        public \DateTimeImmutable $lastDay,
    ) {
    }
}
