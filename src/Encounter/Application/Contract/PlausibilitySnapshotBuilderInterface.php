<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Domain\Entity\PlausibilitySnapshot;

/**
 * Writes the memberships and cells of a draft snapshot from the curated problem areas (ADR 033), replacing what the
 * draft held: each PZC with its area, one fallback cell per area × diagram and one exact cell per PZC × diagram of its
 * area, with the rule's points, source `curated` and counts 0. Same rule as the seed migration Version20261113235145.
 */
interface PlausibilitySnapshotBuilderInterface
{
    public function rebuildFromAreas(PlausibilitySnapshot $draft): void;
}
