<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

final readonly class ClosureVolumeWindowResult
{
    public function __construct(
        public string $kind,
        public ?float $observedArea,
        public ?float $expectedArea,
        public ?float $observedHospital,
        public ?float $expectedHospital,
        public ?float $absoluteDeviation,
        public ?float $relativeDeviation,
        public int $evaluableSeconds,
        public int $requestedSeconds,
        public bool $influenced,
        public bool $complete,
        public bool $computable,
        public ClosureVolumeQuality $quality,
    ) {
    }
}
