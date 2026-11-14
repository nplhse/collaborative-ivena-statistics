<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

use App\Encounter\Domain\Entity\PlausibilitySnapshot;

/**
 * What a reviewer sees of a snapshot before activating it (ADR 033, Privacy): per problem area its PZC codes and the
 * points and source of its fallback cells, and the change against the active snapshot; per PZC × diagram the
 * effective bonus (lookup rule, so a learned exact value shows only for cells with enough observations) where it
 * differs from the active snapshot. No counts, totals or rates.
 *
 * Without an active snapshot every bonus is 0 today: `before` is null for the area cells and `pairChanges` stays empty
 * (the area cells already show what activation gives).
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the snapshot review template.
 */
final readonly class PlausibilitySnapshotReview
{
    /**
     * @param list<PlausibilityAreaReview> $areas
     * @param list<PlausibilityCellChange> $pairChanges ordered by PZC and diagram code
     */
    public function __construct(
        public PlausibilitySnapshot $snapshot,
        public ?int $activeSnapshotId,
        public array $areas,
        public array $pairChanges,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod Called by the template. */
    public function comparedWithActive(): bool
    {
        return null !== $this->activeSnapshotId && $this->activeSnapshotId !== $this->snapshot->getId();
    }

    /** @psalm-suppress PossiblyUnusedMethod Called by the template. */
    public function changedAreaCells(): int
    {
        $changed = 0;
        foreach ($this->areas as $area) {
            foreach ($area->cells as $cell) {
                $changed += $cell->isChanged() ? 1 : 0;
            }
        }

        return $changed;
    }
}
