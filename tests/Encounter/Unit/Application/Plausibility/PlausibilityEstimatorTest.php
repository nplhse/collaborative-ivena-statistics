<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Plausibility;

use App\Encounter\Application\Plausibility\PlausibilityEstimator;
use App\Encounter\Application\Plausibility\PlausibilityLearnedCell;
use App\Encounter\Application\Plausibility\PlausibilityLearningSettings;
use App\Encounter\Application\Plausibility\PlausibilityObservation;
use App\Encounter\Application\Plausibility\PlausibilityTable;
use App\Encounter\Domain\Enum\PlausibilityCellSource;
use PHPUnit\Framework\TestCase;

/**
 * Estimation of learned plausibility cells (ADR 033, #1155) with small inputs whose points follow by hand.
 *
 * Seed: PZC 711 and 712 belong to the area "urologie", whose rule gives `urologisches_problem` (U) +3. B is
 * `bauchschmerzen`, X any other diagram. Rule per cell (α smoothing, k shrinkage, K distinct diagrams in the row):
 * raw = log2(((p + α) / (P + α·K)) / ((n + α) / (N + α·K))), value = ((p + n) · raw + k · seed) / (p + n + k),
 * points = round(value) clipped to 0 … +3.
 */
final class PlausibilityEstimatorTest extends TestCase
{
    private const string U = 'urologisches_problem';

    private const string B = 'bauchschmerzen';

    private const string X = 'atemnot';

    public function testPointsFollowTheLikelihoodRatioShrunkTowardsTheSeed(): void
    {
        // PZC 711: positives U 3, B 1 (P = 4); negatives U 1, B 5 (N = 6); K = 2.
        $observations = [
            $this->observation(711, self::U, [self::B, self::B]),
            $this->observation(711, self::U, [self::B, self::U]),
            $this->observation(711, self::U, [self::B]),
            $this->observation(711, self::B, [self::B]),
        ];

        // U: raw = log2((4/6) / (2/8)) = log2(2.6667) = 1.4150; value = (4 · 1.4150 + 10 · 3) / 14 = 2.5472 → +3.
        // B: raw = log2((2/6) / (6/8)) = log2(0.4444) = −1.1699; no rule, seed 0: value = 6 · −1.1699 / 16 = −0.4387 → 0.
        $cells = $this->estimator(shrinkage: 10.0)->estimate($observations, $this->seed());

        self::assertSame([
            '711|B +0 learned 1/5',
            '711|U +3 learned 3/1',
            'urologie|B +0 learned 1/5',
            'urologie|U +3 learned 3/1',
        ], $this->describe($cells));
        self::assertEqualsWithDelta(-0.4387, $cells[0]->estimate, 0.0001);
        self::assertEqualsWithDelta(2.5472, $cells[1]->estimate, 0.0001);

        // Without shrinkage the data alone gives U round(1.4150) = +1: the seed pulls the cell up to +3.
        $unshrunk = $this->estimator(shrinkage: 0.0)->estimate($observations, $this->seed());
        self::assertSame(['711|B +0 learned 1/5', '711|U +1 learned 3/1'], \array_slice($this->describe($unshrunk), 0, 2));
    }

    public function testTheBonusIsCappedAtThreeAndNeverNegative(): void
    {
        // PZC 500 (no area, seed 0): 20 partners U, 20 other candidates X; P = 20, N = 20, K = 2, no shrinkage.
        // U: raw = log2((21/22) / (1/22)) = log2(21) = 4.3923 → 4, capped at +3. X: log2(1/21) = −4.3923 → 0.
        $observations = array_fill(0, 20, $this->observation(500, self::U, [self::X]));

        $cells = $this->estimator(shrinkage: 0.0)->estimate($observations, $this->seed());

        self::assertSame(['500|X +0 learned 0/20', '500|U +3 learned 20/0'], $this->describe($cells));
        self::assertEqualsWithDelta(4.3923, $cells[1]->estimate, 0.0001);
        self::assertEqualsWithDelta(-4.3923, $cells[0]->estimate, 0.0001);
    }

    public function testCellsBelowTheMinimumKeepTheSeedPoints(): void
    {
        // Minimum 4: U has 1 observation and keeps the seed +3 (counts stored, source curated); B has 1 observation and
        // no seed cell, so it is not written. PZC 500 has no area; a pair without PZC adds nothing.
        $observations = [
            $this->observation(711, self::U, [self::B]),
            $this->observation(500, self::U, []),
            $this->observation(null, self::U, [self::B, self::B, self::B, self::B]),
        ];

        $cells = $this->estimator(shrinkage: 10.0)->estimate($observations, $this->seed());

        self::assertSame(['711|U +3 curated 1/0', 'urologie|U +3 curated 1/0'], $this->describe($cells));
        self::assertNull($cells[0]->estimate);
    }

    public function testCrossFittingLearnsEachFoldFromTheOtherDaysOnly(): void
    {
        // 2026-10-12 is day 20738 since 1970-01-01 (fold 0), 2026-10-13 day 20739 (fold 1).
        $settings = new PlausibilityLearningSettings(smoothing: 1.0, shrinkage: 0.0, minObservations: 2);
        self::assertSame([0, 1], [$settings->foldOf('2026-10-12'), $settings->foldOf('2026-10-13')]);

        // Fold 0: partners U against X; fold 1: partners X against U (PZC 500, no seed).
        $observations = [
            $this->observation(500, self::U, [self::X], '2026-10-12'),
            $this->observation(500, self::U, [self::X], '2026-10-12'),
            $this->observation(500, self::X, [self::U], '2026-10-13'),
            $this->observation(500, self::X, [self::U], '2026-10-13'),
        ];
        $estimator = new PlausibilityEstimator($settings);
        $tables = $estimator->foldTables($observations, $this->seed(2));

        // Table for fold 0, learned on fold 1 only: P = 2 (X), N = 2 (U), K = 2.
        // X: log2((3/4) / (1/4)) = log2(3) = 1.585 → +2; U: log2((1/4) / (3/4)) = −1.585 → 0. Fold 1 the other way round.
        self::assertSame([2, 0], [$tables[0]->lookup(500, self::X)->points, $tables[0]->lookup(500, self::U)->points]);
        self::assertSame([0, 2], [$tables[1]->lookup(500, self::X)->points, $tables[1]->lookup(500, self::U)->points]);

        // Learned on all days the two folds cancel out: p = n = 2 for both diagrams, raw = log2(1) = 0.
        $all = $estimator->table($estimator->estimate($observations, $this->seed(2)), $this->seed(2));
        self::assertSame([0, 0], [$all->lookup(500, self::X)->points, $all->lookup(500, self::U)->points]);
    }

    private function estimator(float $shrinkage): PlausibilityEstimator
    {
        return new PlausibilityEstimator(new PlausibilityLearningSettings(smoothing: 1.0, shrinkage: $shrinkage, minObservations: 4));
    }

    private function seed(int $minObservations = 4): PlausibilityTable
    {
        return new PlausibilityTable(
            1,
            $minObservations,
            [711 => 'urologie', 712 => 'urologie'],
            [],
            ['urologie' => [self::U => ['points' => 3, 'source' => PlausibilityCellSource::Curated]]],
        );
    }

    /**
     * @param list<string> $others
     */
    private function observation(?int $pzc, ?string $partner, array $others, string $day = '2026-10-12'): PlausibilityObservation
    {
        return new PlausibilityObservation($day, $pzc, $partner, $others);
    }

    /**
     * @param list<PlausibilityLearnedCell> $cells
     *
     * @return list<string>
     */
    private function describe(array $cells): array
    {
        $short = [self::U => 'U', self::B => 'B', self::X => 'X'];

        return array_map(static fn (PlausibilityLearnedCell $cell): string => \sprintf(
            '%s|%s +%d %s %d/%d',
            $cell->pzc ?? $cell->areaCode,
            $short[$cell->diagramCode],
            $cell->points,
            $cell->source->value,
            $cell->positives,
            $cell->negatives,
        ), $cells);
    }
}
