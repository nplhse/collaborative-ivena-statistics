<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

final readonly class ClosureVolumeBurdenSlice
{
    public function __construct(
        public int $hospitalId,
        public int $departmentId,
        public int $urgencyCode,
        public string $stratum,
        public string $bucketStart,
        public ?float $observedArea,
        public ?float $expectedArea,
        public int $bucketSeconds,
        public int $overlapSeconds,
        public \DateTimeImmutable $referenceCutoff,
        public int $specialityId = 0,
    ) {
    }
}
