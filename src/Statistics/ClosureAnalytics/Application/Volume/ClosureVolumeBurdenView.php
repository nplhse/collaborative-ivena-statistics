<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

final readonly class ClosureVolumeBurdenView
{
    public function __construct(
        public ClosureVolumeStratum $stratum,
        public bool $built,
        public ?float $observedArea,
        public ?float $expectedArea,
        public ?float $absoluteDeviation,
        public ?float $relativeDeviation,
        public ?float $expectedHospital,
        public ?float $share,
        public bool $partialReference,
        public bool $partialObservation,
        public ClosureVolumeReferenceWindow $referenceWindow,
        public int $minimumReferenceSlots,
    ) {
    }

    public static function unavailable(ClosureVolumeStratum $stratum, ClosureVolumeReferenceWindow $referenceWindow, int $minimumReferenceSlots): self
    {
        return new self($stratum, false, null, null, null, null, null, null, false, false, $referenceWindow, $minimumReferenceSlots);
    }
}
