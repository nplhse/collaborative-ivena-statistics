<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

use App\Encounter\Application\Contract\PlausibilityTableReaderInterface;
use App\Encounter\Domain\Entity\PlausibilitySnapshot;

/**
 * Builds the review of a snapshot against the active snapshot (PlausibilitySnapshotReview, ADR 033).
 */
final readonly class PlausibilityReviewer
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private PlausibilityLookup $lookup,
        private PlausibilityTableReaderInterface $reader,
    ) {
    }

    public function review(PlausibilitySnapshot $snapshot): PlausibilitySnapshotReview
    {
        $snapshotId = (int) $snapshot->getId();
        $table = $this->lookup->table($snapshotId);
        $activeId = $this->reader->activeSnapshotId();
        $active = null === $activeId || $activeId === $snapshotId ? null : $this->lookup->table($activeId);

        return new PlausibilitySnapshotReview(
            $snapshot,
            $activeId,
            $this->areas($table, $active),
            $active instanceof PlausibilityTable ? $this->pairChanges($table, $active) : [],
        );
    }

    /**
     * @return list<PlausibilityAreaReview>
     */
    private function areas(PlausibilityTable $table, ?PlausibilityTable $active): array
    {
        $codes = [];
        foreach ($table->areas() as $pzc => $area) {
            $codes[$area][] = $pzc;
        }

        $cells = $table->areaCells();
        $before = $active?->areaCells() ?? [];
        $areaCodes = array_unique([...array_keys($codes), ...array_keys($cells), ...array_keys($before)]);
        sort($areaCodes, \SORT_STRING);

        $areas = [];
        foreach ($areaCodes as $area) {
            $diagrams = array_unique([...array_keys($cells[$area] ?? []), ...array_keys($before[$area] ?? [])]);
            sort($diagrams, \SORT_STRING);
            $pzcCodes = $codes[$area] ?? [];
            sort($pzcCodes);

            $areas[] = new PlausibilityAreaReview($area, $pzcCodes, array_map(
                static fn (string $diagram): PlausibilityCellChange => new PlausibilityCellChange(
                    $diagram,
                    $cells[$area][$diagram]['points'] ?? null,
                    $before[$area][$diagram]['points'] ?? null,
                    $cells[$area][$diagram]['source'] ?? null,
                ),
                $diagrams,
            ));
        }

        return $areas;
    }

    /**
     * @return list<PlausibilityCellChange>
     */
    private function pairChanges(PlausibilityTable $table, PlausibilityTable $active): array
    {
        $pairs = [];
        foreach ([...$table->pairs(), ...$active->pairs()] as [$pzc, $diagram]) {
            $pairs[\sprintf('%03d|%s', $pzc, $diagram)] = [$pzc, $diagram];
        }

        ksort($pairs, \SORT_STRING);
        $changes = [];
        foreach ($pairs as [$pzc, $diagram]) {
            $after = $table->lookup($pzc, $diagram);
            $before = $active->lookup($pzc, $diagram);
            if ($after->points !== $before->points) {
                $changes[] = new PlausibilityCellChange($diagram, $after->points, $before->points, $after->source, $pzc);
            }
        }

        return $changes;
    }
}
