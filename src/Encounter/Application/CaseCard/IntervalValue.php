<?php

declare(strict_types=1);

namespace App\Encounter\Application\CaseCard;

use App\Encounter\Application\Overview\ProcessInterval;

/**
 * One process interval of a single visit: the minutes, or null when a timestamp is missing.
 * Implausible values (negative or above ProcessInterval::MAX_PLAUSIBLE_MINUTES) are shown with a
 * warning, as the overview excludes them.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the case card template.
 */
final readonly class IntervalValue
{
    public bool $plausible;

    public function __construct(
        public ProcessInterval $interval,
        public ?int $minutes,
    ) {
        $this->plausible = null === $minutes || $interval->isPlausible($minutes);
    }
}
