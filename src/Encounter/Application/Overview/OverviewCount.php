<?php

declare(strict_types=1);

namespace App\Encounter\Application\Overview;

use App\Encounter\Application\Glossary\MtsCount;

/**
 * One count of the encounter overview with its share of a stated denominator. Counts of 1 to 4 are
 * suppressed as in the Explore catalog and the MTS glossary (ADR 012, MtsCount); a suppressed count
 * has no share either, nor has a count of 0.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the overview templates.
 */
final readonly class OverviewCount
{
    public MtsCount $count;

    /** Percent of the denominator, null when the count is 0 or suppressed or the denominator is 0. */
    public ?float $share;

    public function __construct(
        public string $key,
        int $count,
        int $denominator,
    ) {
        $this->count = new MtsCount($count);
        $this->share = $this->count->suppressed || $count <= 0 || $denominator <= 0 ? null : $count * 100 / $denominator;
    }

    /**
     * Value safe to show or chart: null when suppressed.
     *
     * @psalm-suppress PossiblyUnusedMethod Called by the template.
     */
    public function shownValue(): ?int
    {
        return $this->count->value;
    }
}
