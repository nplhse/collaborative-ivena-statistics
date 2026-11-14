<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Plausibility;

use App\Encounter\Application\Plausibility\PlausibilityHit;
use App\Encounter\Application\Plausibility\PlausibilityLevel;
use App\Encounter\Application\Plausibility\PlausibilityTable;
use App\Encounter\Domain\Enum\PlausibilityCellSource;
use PHPUnit\Framework\TestCase;

/**
 * Lookup rule of the plausibility table (ADR 033) on a hand-made snapshot with minimum 10 observations:
 *
 * | Key                          | Cell                                  |
 * |------------------------------|---------------------------------------|
 * | 711 → urologie               | membership                            |
 * | 712 → urologie               | membership                            |
 * | 711 × urologisches_problem   | exact, +1, 6 + 4 = 10 observations    |
 * | 712 × urologisches_problem   | exact, +0, 5 + 4 = 9 observations     |
 * | 711 × hodenschmerz           | exact, +2, 30 + 20 observations       |
 * | urologie × urologisches_prob | area, +3                              |
 * | urologie × rueckenschmerz    | area, +2                              |
 * | 999 × kollaps                | exact, +5 (beyond the cap), 12 obs.   |
 */
final class PlausibilityTableTest extends TestCase
{
    public function testExactCellWithEnoughObservationsWins(): void
    {
        $hit = $this->table()->lookup(711, 'urologisches_problem');

        self::assertEquals(new PlausibilityHit(1, PlausibilityLevel::Exact, 7, PlausibilityCellSource::Learned), $hit, '10 observations reach the minimum of 10, so +1 instead of the area +3');
    }

    public function testExactCellBelowTheMinimumFallsBackToTheProblemArea(): void
    {
        $hit = $this->table()->lookup(712, 'urologisches_problem');

        self::assertEquals(new PlausibilityHit(3, PlausibilityLevel::Area, 7, PlausibilityCellSource::Curated), $hit, '9 observations: the area cell +3 applies, not the exact +0');
        self::assertSame(2, $this->table()->lookup(712, 'rueckenschmerz')->points, 'no exact cell: the area cell');
    }

    public function testMinimumIsConfigurable(): void
    {
        self::assertSame(0, $this->table(9)->lookup(712, 'urologisches_problem')->points, 'with minimum 9 the exact +0 applies');
        self::assertSame(3, $this->table(11)->lookup(711, 'urologisches_problem')->points, 'with minimum 11 the area +3 applies');
    }

    public function testNoCellGivesZero(): void
    {
        $table = $this->table();

        self::assertEquals(PlausibilityHit::none(7), $table->lookup(711, 'thoraxschmerz'), 'area without a cell for the diagram');
        self::assertEquals(PlausibilityHit::none(7), $table->lookup(329, 'urologisches_problem'), 'PZC in no area');
        self::assertEquals(PlausibilityHit::none(7), $table->lookup(null, 'urologisches_problem'), 'no PZC');
        self::assertEquals(PlausibilityHit::none(7), $table->lookup(711, null), 'no diagram');
        self::assertEquals(PlausibilityHit::none(7), $table->lookup(711, ''), 'empty diagram');
    }

    public function testPointsAreCappedAtThree(): void
    {
        self::assertSame(3, $this->table()->lookup(999, 'kollaps')->points);
    }

    public function testPairsListExactCellsAndAreaCodesWithTheirDiagrams(): void
    {
        $pairs = $this->table()->pairs();
        sort($pairs);

        self::assertSame([
            [711, 'hodenschmerz'],
            [711, 'rueckenschmerz'],
            [711, 'urologisches_problem'],
            [712, 'rueckenschmerz'],
            [712, 'urologisches_problem'],
            [999, 'kollaps'],
        ], $pairs);
    }

    private function table(int $minObservations = 10): PlausibilityTable
    {
        $learned = PlausibilityCellSource::Learned;
        $curated = PlausibilityCellSource::Curated;

        return new PlausibilityTable(
            7,
            $minObservations,
            [711 => 'urologie', 712 => 'urologie'],
            [
                711 => [
                    'urologisches_problem' => ['points' => 1, 'observations' => 10, 'source' => $learned],
                    'hodenschmerz' => ['points' => 2, 'observations' => 50, 'source' => $learned],
                ],
                712 => ['urologisches_problem' => ['points' => 0, 'observations' => 9, 'source' => $learned]],
                999 => ['kollaps' => ['points' => 5, 'observations' => 12, 'source' => $learned]],
            ],
            ['urologie' => [
                'urologisches_problem' => ['points' => 3, 'source' => $curated],
                'rueckenschmerz' => ['points' => 2, 'source' => $curated],
            ]],
        );
    }
}
