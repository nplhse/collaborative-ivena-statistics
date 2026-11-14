<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Application\Plausibility\PlausibilityLearnedCell;
use App\Encounter\Domain\Entity\PlausibilitySnapshot;

/**
 * Writes the memberships and cells of a draft snapshot from the curated problem areas (ADR 033), replacing what the
 * draft held: each PZC with its area, one fallback cell per area × diagram and one exact cell per PZC × diagram of its
 * area, with the rule's points, source `curated` and counts 0. Same rule as the seed migration Version20261113235145.
 */
interface PlausibilitySnapshotBuilderInterface
{
    public function rebuildFromAreas(PlausibilitySnapshot $draft): void;

    /**
     * Writes estimated cells into a draft built from the areas (#1155): a cell the draft holds already (same PZC or
     * area and diagram, no indicator) gets the cell's points, source and counts; any other cell is added.
     *
     * @param list<PlausibilityLearnedCell> $cells
     */
    public function writeLearnedCells(PlausibilitySnapshot $draft, array $cells): void;
}
