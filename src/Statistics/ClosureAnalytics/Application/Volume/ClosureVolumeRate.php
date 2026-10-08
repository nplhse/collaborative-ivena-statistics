<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

final readonly class ClosureVolumeRate
{
    public function __construct(
        public ?float $hourlyRate,
        public ClosureVolumeReferenceMode $mode,
        public int $slotCount,
        public int $assignmentCount,
    ) {
    }

    public function expectedForSeconds(int $seconds): ?float
    {
        if (null === $this->hourlyRate) {
            return null;
        }

        return $this->hourlyRate * ((float) $seconds / 3600.0);
    }
}
