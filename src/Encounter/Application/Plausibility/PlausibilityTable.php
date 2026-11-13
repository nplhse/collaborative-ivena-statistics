<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

use App\Encounter\Domain\Entity\PlausibilityCell;
use App\Encounter\Domain\Enum\PlausibilityCellSource;

/**
 * The cells and PZC → problem-area memberships of one snapshot in memory, with the lookup rule of ADR 033:
 *
 * 1. the exact cell (PZC × diagram) if it has at least the minimum number of observations (positives + negatives),
 * 2. otherwise the fallback cell of the PZC's problem area × diagram,
 * 3. otherwise 0.
 *
 * A pair without PZC or without diagram gets 0. Indicator cells are reserved and never loaded. Points are capped at
 * 0 … +3. The diagram code must already be canonical (PlausibilityLookup resolves aliases).
 *
 * @phpstan-type ExactCell array{points: int, observations: int, source: PlausibilityCellSource}
 * @phpstan-type AreaCell array{points: int, source: PlausibilityCellSource}
 */
final readonly class PlausibilityTable
{
    /**
     * @param array<int, string>                     $areas     problem-area code by PZC
     * @param array<int, array<string, ExactCell>>   $exact     by PZC and diagram code
     * @param array<string, array<string, AreaCell>> $areaCells by area code and diagram code
     */
    public function __construct(
        public int $snapshotId,
        private int $minObservations,
        private array $areas,
        private array $exact,
        private array $areaCells,
    ) {
    }

    public function lookup(?int $pzc, ?string $diagramCode): PlausibilityHit
    {
        if (null === $pzc || null === $diagramCode || '' === $diagramCode) {
            return PlausibilityHit::none($this->snapshotId);
        }

        $exact = $this->exact[$pzc][$diagramCode] ?? null;
        if (null !== $exact && $exact['observations'] >= $this->minObservations) {
            return new PlausibilityHit($this->cap($exact['points']), PlausibilityLevel::Exact, $this->snapshotId, $exact['source']);
        }

        $area = $this->areas[$pzc] ?? null;
        $cell = null === $area ? null : $this->areaCells[$area][$diagramCode] ?? null;
        if (null !== $cell) {
            return new PlausibilityHit($this->cap($cell['points']), PlausibilityLevel::Area, $this->snapshotId, $cell['source']);
        }

        return PlausibilityHit::none($this->snapshotId);
    }

    /**
     * @return array<int, string> problem-area code by PZC
     */
    public function areas(): array
    {
        return $this->areas;
    }

    /**
     * @return array<string, array<string, AreaCell>>
     */
    public function areaCells(): array
    {
        return $this->areaCells;
    }

    /**
     * Every PZC × diagram pair this table can give points to: the exact cells and the PZC codes of each area with the
     * diagrams of its fallback cells.
     *
     * @return list<array{0: int, 1: string}>
     */
    public function pairs(): array
    {
        $pairs = [];
        foreach ($this->exact as $pzc => $cells) {
            foreach (array_keys($cells) as $diagram) {
                $pairs[$pzc.'|'.$diagram] = [$pzc, $diagram];
            }
        }

        foreach ($this->areas as $pzc => $area) {
            foreach (array_keys($this->areaCells[$area] ?? []) as $diagram) {
                $pairs[$pzc.'|'.$diagram] = [$pzc, $diagram];
            }
        }

        return array_values($pairs);
    }

    private function cap(int $points): int
    {
        return max(PlausibilityCell::MIN_POINTS, min(PlausibilityCell::MAX_POINTS, $points));
    }
}
