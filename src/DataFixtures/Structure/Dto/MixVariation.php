<?php

declare(strict_types=1);

namespace App\DataFixtures\Structure\Dto;

/**
 * How much the case mix of one hospital may differ from its segment (ADR 022).
 *
 * Each spread is the standard deviation of the log of a factor drawn once per hospital: one per
 * indication category (first PZC digit), one for the oldest age bands and one for urgency 1.
 */
final readonly class MixVariation
{
    public function __construct(
        public float $categorySpread,
        public float $oldAgeSpread,
        public float $urgentSpread,
    ) {
    }
}
