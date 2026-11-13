<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital\MyHospitals;

use App\Allocation\Application\DTO\MyHospitals\CoveredPeriod;
use App\Allocation\Application\DTO\MyHospitals\HospitalDataSnapshot;
use App\Allocation\Application\DTO\MyHospitals\ImportIssue;
use App\Allocation\Application\DTO\MyHospitals\UploaderContribution;

/**
 * Builds snapshots for tests with only the fields a test cares about.
 */
final class SnapshotBuilder
{
    /**
     * @param array<string, int>               $volumes   'Y-m' => allocations
     * @param list<array{string, string}>      $periods   first and last day ('Y-m-d')
     * @param list<ImportIssue>                $issues
     * @param array<int, UploaderContribution> $uploaders
     */
    public static function snapshot(
        int $hospitalId = 1,
        array $volumes = [],
        array $periods = [],
        ?string $firstAllocationAt = null,
        ?string $lastAllocationAt = null,
        array $issues = [],
        ?string $lastUploadAt = null,
        int $relevantImports = 0,
        int $failedImports = 0,
        array $uploaders = [],
    ): HospitalDataSnapshot {
        return new HospitalDataSnapshot(
            hospitalId: $hospitalId,
            allocationCount: array_sum($volumes),
            firstAllocationAt: null === $firstAllocationAt ? null : new \DateTimeImmutable($firstAllocationAt),
            lastAllocationAt: null === $lastAllocationAt ? null : new \DateTimeImmutable($lastAllocationAt),
            monthlyVolumes: $volumes,
            coveredPeriods: array_map(
                static fn (array $period): CoveredPeriod => new CoveredPeriod(new \DateTimeImmutable($period[0]), new \DateTimeImmutable($period[1])),
                $periods,
            ),
            relevantImports: $relevantImports,
            partialImports: 0,
            failedImports: $failedImports,
            cancelledImports: 0,
            pendingImports: 0,
            lastUploadAt: null === $lastUploadAt ? null : new \DateTimeImmutable($lastUploadAt),
            uploaders: $uploaders,
            importIssues: $issues,
        );
    }
}
