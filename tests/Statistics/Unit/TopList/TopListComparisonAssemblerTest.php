<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\TopList;

use App\Statistics\Application\TopList\TopListComparisonAssembler;
use App\Statistics\Application\TopList\TopListRankedRow;
use App\Statistics\Application\TopList\TopListRanking;
use PHPUnit\Framework\TestCase;

final class TopListComparisonAssemblerTest extends TestCase
{
    public function testBuildsIndependentSideRankingsWithBDeltas(): void
    {
        $rankingA = new TopListRanking([
            new TopListRankedRow('1', 'Alpha', 20, 50.0, 1, 1),
            new TopListRankedRow('2', 'Beta', 10, 25.0, 2, 2),
            new TopListRankedRow('3', 'Gamma', 5, 12.5, 3, 3),
        ], 40);
        $rankingB = new TopListRanking([
            new TopListRankedRow('2', 'Beta', 30, 60.0, 1, 2),
            new TopListRankedRow('1', 'Alpha', 15, 30.0, 2, 1),
            new TopListRankedRow('4', 'Delta', 5, 10.0, 3, 4),
        ], 50);

        $comparison = new TopListComparisonAssembler()->assemble($rankingA, $rankingB);

        self::assertCount(3, $comparison->rowsA);
        self::assertCount(3, $comparison->rowsB);
        self::assertSame(40, $comparison->totalAllocationsA);
        self::assertSame(50, $comparison->totalAllocationsB);

        self::assertSame('Alpha', $comparison->rowsA[0]->label);
        self::assertSame(1, $comparison->rowsA[0]->rankA);
        self::assertSame('Gamma', $comparison->rowsA[2]->label);
        self::assertTrue($comparison->rowsA[2]->onlyInA);

        $beta = $comparison->rowsB[0];
        self::assertSame('Beta', $beta->label);
        self::assertSame(1, $beta->rankB);
        self::assertSame(2, $beta->rankA);
        self::assertSame(20, $beta->deltaCount);
        self::assertSame(35.0, $beta->deltaShare);
        self::assertSame(1, $beta->rankMovement);

        $delta = $comparison->rowsB[2];
        self::assertSame('Delta', $delta->label);
        self::assertTrue($delta->onlyInB);
        self::assertSame(5, $delta->deltaCount);
        self::assertNull($delta->rankA);
    }

    public function testPageSliceDoesNotRepeatShorterSide(): void
    {
        $rowsA = [];
        $rowsB = [];
        for ($i = 1; $i <= 2; ++$i) {
            $rowsA[] = new TopListRankedRow((string) $i, 'A'.$i, $i, 50.0, $i, $i);
        }
        for ($i = 1; $i <= 4; ++$i) {
            $rowsB[] = new TopListRankedRow((string) $i, 'B'.$i, $i, 25.0, $i, $i);
        }

        $page = new TopListComparisonAssembler()
            ->assemble(new TopListRanking($rowsA, 3), new TopListRanking($rowsB, 10))
            ->pageSlice(2, 2);

        self::assertCount(0, $page->rowsA);
        self::assertCount(2, $page->rowsB);
        self::assertSame('B3', $page->rowsB[0]->label);
        self::assertSame('B4', $page->rowsB[1]->label);
    }
}
