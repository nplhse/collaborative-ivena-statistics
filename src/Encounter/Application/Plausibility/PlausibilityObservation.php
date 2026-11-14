<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

/**
 * One eligible pair of the learning job (ADR 033) with the other candidates of its allocation: the allocation's PZC,
 * the canonical MTS diagram of the partner visit (positive) and those of the other candidate visits in the pass-2
 * window (negatives). Visits without a diagram are left out of the negatives; a partner without one adds no positive.
 */
final readonly class PlausibilityObservation
{
    /**
     * @param list<string> $otherDiagrams canonical diagram codes of the other candidates
     */
    public function __construct(
        /** IVENA arrival day (YYYY-MM-DD), decides the fold of the cross-fitted replay. */
        public string $day,
        public ?int $pzc,
        public ?string $partnerDiagram,
        public array $otherDiagrams,
    ) {
    }
}
