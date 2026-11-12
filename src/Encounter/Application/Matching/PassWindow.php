<?php

declare(strict_types=1);

namespace App\Encounter\Application\Matching;

/**
 * Candidate window of one matcher pass (ADR 029): matching anchor from `minutesBefore` before to
 * `minutesAfter` after the IVENA arrival (both inclusive) and at most `maxAgeDifference` years apart.
 */
final readonly class PassWindow
{
    public function __construct(
        public int $minutesBefore,
        public int $minutesAfter,
        public int $maxAgeDifference,
        public bool $emsOnly,
    ) {
        if ($minutesBefore < 0 || $minutesAfter < 0 || $maxAgeDifference < 0) {
            throw new \InvalidArgumentException('Window bounds and age difference must not be negative.');
        }
    }

    public function contains(int $timeDifferenceMinutes, int $ageDifference): bool
    {
        return $timeDifferenceMinutes >= -$this->minutesBefore
            && $timeDifferenceMinutes <= $this->minutesAfter
            && abs($ageDifference) <= $this->maxAgeDifference;
    }
}
