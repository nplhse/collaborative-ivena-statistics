<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * One row of closure_volume_hour: event × population × metric × bucket.
 */
final readonly class ClosureVolumeHourDraft
{
    public function __construct(
        public int $eventId,
        public int $hospitalId,
        public string $scope,
        public int $specialityId,
        public int $departmentId,
        public ClosureVolumeMetricKind $metric,
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
        public int $blockedSlotCount,
        public int $referenceAssignmentCount,
        public string $referenceMode,
        public bool $influenced,
        public string $quality,
        public ClosureVolumeRelevance $relevance,
        public int $closedDepartmentCount,
        public bool $provisional,
        public ?ClosureVolumeProvisionalReason $provisionalReason,
        public \DateTimeImmutable $referenceCutoff,
    ) {
    }
}
