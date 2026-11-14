<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

use App\Encounter\Domain\Entity\PlausibilityCell;
use App\Encounter\Domain\Enum\PlausibilityCellSource;

/**
 * Estimates the cells of a learned plausibility snapshot from eligible pairs (ADR 033, #1155). Pure.
 *
 * Rows and cells: an exact row is one PZC, a fallback row one problem area (the PZC's area in the seed); a cell is a
 * row × canonical diagram. Positives are the partner diagrams of the eligible pairs in the row, negatives the diagrams
 * of the other candidates of the same allocations. Shares are taken within the row, because the candidates of one
 * allocation share its PZC: only the diagram distinguishes the partner from the others.
 *
 * For a cell with p positives and n negatives in a row with P positives, N negatives and K distinct diagrams:
 *
 *     raw    = log2( ((p + α) / (P + α·K)) / ((n + α) / (N + α·K)) )     Laplace smoothing α
 *     value  = ((p + n) · raw + k · seed) / (p + n + k)                    shrinkage k towards the seed points
 *     points = round(value), clipped to 0 … +3                             bonus only, cap +3
 *
 * The seed points of a fallback cell are those of its curated rule, of an exact cell those of its PZC's area rule
 * (0 without a rule). A cell below the minimum observations keeps its seed points (source `curated`) and is returned
 * only when the seed has such a cell, so its counts are stored; the lookup falls back for it as for any sparse exact
 * cell. Cells without observations are not returned; the seed cells written before stay as they are.
 *
 * Indicator cells (reserved) are not estimated.
 */
final readonly class PlausibilityEstimator
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private PlausibilityLearningSettings $settings,
    ) {
    }

    /**
     * @param list<PlausibilityObservation> $observations
     * @param PlausibilityTable             $seed         the curated cells and PZC → area memberships the draft starts from
     *
     * @return list<PlausibilityLearnedCell> exact cells (ordered by PZC and diagram), then fallback cells (by area and diagram)
     */
    public function estimate(array $observations, PlausibilityTable $seed): array
    {
        $areas = $seed->areas();
        /** @var array<int, array<string, array{0: int, 1: int}>> $exact */
        $exact = [];
        /** @var array<string, array<string, array{0: int, 1: int}>> $byArea */
        $byArea = [];
        foreach ($observations as $observation) {
            if (null === $observation->pzc) {
                continue;
            }

            $area = $areas[$observation->pzc] ?? null;
            $diagrams = [];
            if (null !== $observation->partnerDiagram) {
                $diagrams[] = [$observation->partnerDiagram, 0];
            }

            foreach ($observation->otherDiagrams as $diagram) {
                $diagrams[] = [$diagram, 1];
            }

            foreach ($diagrams as [$diagram, $side]) {
                $exact[$observation->pzc][$diagram] ??= [0, 0];
                ++$exact[$observation->pzc][$diagram][$side];
                if (null !== $area) {
                    $byArea[$area][$diagram] ??= [0, 0];
                    ++$byArea[$area][$diagram][$side];
                }
            }
        }

        ksort($exact);
        ksort($byArea, \SORT_STRING);
        $seedCells = $seed->areaCells();
        $cells = [];
        foreach ($exact as $pzc => $row) {
            $area = $areas[$pzc] ?? null;
            foreach ($this->row($row, null === $area ? [] : $seedCells[$area] ?? []) as $diagram => $cell) {
                $cells[] = new PlausibilityLearnedCell($pzc, null, $diagram, ...$cell);
            }
        }

        foreach ($byArea as $area => $row) {
            foreach ($this->row($row, $seedCells[$area] ?? []) as $diagram => $cell) {
                $cells[] = new PlausibilityLearnedCell(null, $area, $diagram, ...$cell);
            }
        }

        return $cells;
    }

    /**
     * The table a matcher replay reads for the given cells (in memory, not stored): the seed's memberships and fallback
     * cells with the estimated cells on top. Same lookup as the stored draft.
     *
     * @param list<PlausibilityLearnedCell> $cells
     */
    public function table(array $cells, PlausibilityTable $seed, int $snapshotId = 0): PlausibilityTable
    {
        $exact = [];
        $areaCells = $seed->areaCells();
        foreach ($cells as $cell) {
            if (null !== $cell->pzc) {
                $exact[$cell->pzc][$cell->diagramCode] = ['points' => $cell->points, 'observations' => $cell->observations(), 'source' => $cell->source];
            } elseif (null !== $cell->areaCode) {
                $areaCells[$cell->areaCode][$cell->diagramCode] = ['points' => $cell->points, 'source' => $cell->source];
            }
        }

        return new PlausibilityTable($snapshotId, $this->settings->minObservations, $seed->areas(), $exact, $areaCells);
    }

    /**
     * Cross-fitting (ADR 033): per fold the table learned without the observations of that fold's days
     * ({@see PlausibilityLearningSettings::foldOf()}), for replaying that fold's allocations.
     *
     * @param list<PlausibilityObservation> $observations
     *
     * @return array<int, PlausibilityTable> by fold
     */
    public function foldTables(array $observations, PlausibilityTable $seed): array
    {
        $tables = [];
        for ($fold = 0; $fold < $this->settings->folds(); ++$fold) {
            $training = array_values(array_filter($observations, fn (PlausibilityObservation $observation): bool => $this->settings->foldOf($observation->day) !== $fold));
            $tables[$fold] = $this->table($this->estimate($training, $seed), $seed);
        }

        return $tables;
    }

    /**
     * @param array<string, array{0: int, 1: int}>                              $row       positives and negatives by diagram
     * @param array<string, array{points: int, source: PlausibilityCellSource}> $seedCells seed cells of the row's area by diagram
     *
     * @return array<string, array{points: int, source: PlausibilityCellSource, positives: int, negatives: int, estimate: ?float}>
     */
    private function row(array $row, array $seedCells): array
    {
        ksort($row, \SORT_STRING);
        $alpha = $this->settings->smoothing;
        $k = \count($row);
        $positives = array_sum(array_column($row, 0));
        $negatives = array_sum(array_column($row, 1));

        $cells = [];
        foreach ($row as $diagram => [$p, $n]) {
            $seedPoints = $seedCells[$diagram]['points'] ?? null;
            $observations = $p + $n;
            if ($observations < $this->settings->minObservations) {
                if (null !== $seedPoints) {
                    $cells[$diagram] = ['points' => $seedPoints, 'source' => PlausibilityCellSource::Curated, 'positives' => $p, 'negatives' => $n, 'estimate' => null];
                }

                continue;
            }

            $positiveShare = ((float) $p + $alpha) / ((float) $positives + $alpha * (float) $k);
            $negativeShare = ((float) $n + $alpha) / ((float) $negatives + $alpha * (float) $k);
            $raw = log($positiveShare / $negativeShare, 2);
            $shrinkage = $this->settings->shrinkage;
            $value = ((float) $observations * $raw + $shrinkage * (float) ($seedPoints ?? 0)) / ((float) $observations + $shrinkage);
            $points = max(PlausibilityCell::MIN_POINTS, min(PlausibilityCell::MAX_POINTS, (int) round($value)));
            $cells[$diagram] = ['points' => $points, 'source' => PlausibilityCellSource::Learned, 'positives' => $p, 'negatives' => $n, 'estimate' => $value];
        }

        return $cells;
    }
}
