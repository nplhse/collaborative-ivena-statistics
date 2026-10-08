<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureProfilePhaseTotals
{
    public function __construct(
        public int $contributingEvents,
        public ?float $observedRate,
        public ?float $expectedRate,
        public ?float $observedMean,
        public ?float $expectedMean,
        public ?float $dedupedObserved,
        public ?float $dedupedExpected,
        public bool $hoursReused,
        public bool $lineSuppressed,
    ) {
    }

    public function rateDeviation(): ?float
    {
        if (null === $this->observedRate || null === $this->expectedRate) {
            return null;
        }

        return $this->observedRate - $this->expectedRate;
    }

    public function volumeDeviation(): ?float
    {
        if (null === $this->dedupedObserved || null === $this->dedupedExpected) {
            return null;
        }

        return $this->dedupedObserved - $this->dedupedExpected;
    }
}
