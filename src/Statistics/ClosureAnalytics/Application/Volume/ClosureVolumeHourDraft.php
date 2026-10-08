<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

final readonly class ClosureVolumeHourDraft
{
    public function __construct(
        public int $closureIntervalId,
        public int $hospitalId,
        public int $departmentId,
        public int $urgencyCode,
        public string $stratum,
        public \DateTimeImmutable $bucketStart,
        public \DateTimeImmutable $bucketEnd,
        public bool $inClosure,
        public ?float $observedArea,
        public ?float $expectedArea,
        public ?float $observedHospital,
        public ?float $expectedHospital,
        public int $evaluableSeconds,
        public int $bucketSeconds,
        public int $referenceSlotCount,
        public int $referenceAssignmentCount,
        public string $referenceMode,
        public bool $influenced,
        public string $quality,
        public \DateTimeImmutable $referenceCutoff,
        public string $scope = 'department',
        public int $specialityId = 0,
    ) {
    }
}
