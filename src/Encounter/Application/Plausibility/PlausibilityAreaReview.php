<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

/**
 * One problem area of a reviewed snapshot: its PZC codes in that snapshot and its fallback cells against the active
 * snapshot.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the snapshot review template.
 */
final readonly class PlausibilityAreaReview
{
    /**
     * @param list<int>                    $pzcCodes
     * @param list<PlausibilityCellChange> $cells    ordered by diagram code
     */
    public function __construct(
        public string $code,
        public array $pzcCodes,
        public array $cells,
    ) {
    }
}
