<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\Allocation\Domain\Entity\Hospital;

/**
 * One synthetic import per hospital and month, like real monthly IVENA uploads.
 */
final readonly class ImportBatch
{
    /**
     * @param string             $month       month as Y-m
     * @param array<string, int> $dailyCounts allocations per day (Y-m-d)
     */
    public function __construct(
        public Hospital $hospital,
        public string $month,
        public string $importName,
        public array $dailyCounts,
    ) {
    }

    public function allocationCount(): int
    {
        return array_sum($this->dailyCounts);
    }
}
