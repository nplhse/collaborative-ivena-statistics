<?php

declare(strict_types=1);

namespace App\Encounter\Application\Overview;

/**
 * Coverage of the stored visits of one hospital: visits, distinct owning imports, distinct anchor
 * days and the first and last anchor.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the overview template.
 */
final readonly class EncounterCoverage
{
    public function __construct(
        public int $visits,
        public int $imports,
        public int $days,
        public ?\DateTimeImmutable $firstAnchor,
        public ?\DateTimeImmutable $lastAnchor,
    ) {
    }
}
