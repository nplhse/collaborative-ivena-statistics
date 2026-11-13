<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Review;

use App\Encounter\Application\Matching\MatchingSettings;
use App\Encounter\Application\Review\SwapPlanner;
use App\Encounter\Application\Review\SwapProposal;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\Sex;
use App\Tests\Encounter\Unit\Application\Matching\MatchingTestData;
use PHPUnit\Framework\TestCase;

/**
 * Targeted swap proposals (ADR 029, #1054). Allocation 1 arrives at 10:00 and is linked automatically to
 * visit 10 at 10:10 (time +4, sex +2, age +2, route +1 = 9 points) unless stated otherwise.
 */
final class SwapPlannerTest extends TestCase
{
    use MatchingTestData;

    public function testAnUnlinkedVisitThatFreesTheLinkedVisitForAnUnlinkedAllocationGivesAChain(): void
    {
        // Visit 11, a self-presenter at 10:05: 4 + 2 + 2 − 2 = 6 points for allocation 1.
        // Allocation 2 at 10:05, age 72: visit 10 gives 4 + 2 + 0 + 1 = 7 points.
        $proposals = $this->plan(
            [self::allocation(1), self::allocation(2, '10:05', age: 72)],
            [self::visit(10, '10:10'), self::visit(11, '10:05', mode: ArrivalMode::Self)],
        );

        self::assertCount(1, $proposals);
        self::assertSame(SwapProposal::CHAIN, $proposals[0]->kind);
        self::assertSame('1:10:11:2', $proposals[0]->key());
        self::assertSame([9, 6, 7], [$proposals[0]->currentPoints, $proposals[0]->newLink->points, $proposals[0]->otherLink?->points]);
        // 6 + 7 − 9 = 4 points more, one link more.
        self::assertSame([1, 4], [$proposals[0]->linkGain(), $proposals[0]->pointsGain()]);
    }

    public function testABetterFittingUnlinkedVisitGivesAReplacement(): void
    {
        // Linked visit 10 at 10:25, one year older: time +3, age +1, so 3 + 2 + 1 + 1 = 7 points. Visit 11 at
        // 10:03 with arrival mode "other" (route unknown): 4 + 2 + 2 + 0 = 8 points.
        $proposals = $this->plan(
            [self::allocation(1)],
            [self::visit(10, '10:25', age: 71), self::visit(11, '10:03', mode: ArrivalMode::Other)],
        );

        self::assertCount(1, $proposals);
        self::assertSame(SwapProposal::REPLACEMENT, $proposals[0]->kind);
        self::assertSame('1:10:11:0', $proposals[0]->key());
        self::assertSame([0, 1], [$proposals[0]->linkGain(), $proposals[0]->pointsGain()]);
    }

    public function testAWorseUnlinkedVisitWithoutChainGivesNothing(): void
    {
        self::assertSame([], $this->plan(
            [self::allocation(1)],
            [self::visit(10, '10:10'), self::visit(11, '10:05', mode: ArrivalMode::Self)],
        ));
    }

    public function testRejectedPairsAndImplausiblePairsAreNotProposed(): void
    {
        $allocations = [self::allocation(1), self::allocation(2, '10:05', age: 72)];
        $visits = [self::visit(10, '10:10'), self::visit(11, '10:05', mode: ArrivalMode::Self)];

        self::assertSame([], $this->plan($allocations, $visits, rejected: ['1:11' => true]));
        self::assertSame([], $this->plan($allocations, $visits, rejected: ['2:10' => true]));

        // A male self-presenter: 4 − 2 + 2 − 2 = 2 points, alone 4 / 20 = 0.2, below the proposal threshold.
        self::assertSame([], $this->plan($allocations, [self::visit(10, '10:10'), self::visit(11, '10:05', Sex::Male, mode: ArrivalMode::Self)]));
    }

    public function testConfirmedLinksAreNeverSwapped(): void
    {
        self::assertSame([], $this->plan(
            [self::allocation(1), self::allocation(2, '10:05', age: 72)],
            [self::visit(10, '10:10'), self::visit(11, '10:05', mode: ArrivalMode::Self)],
            automatic: false,
        ));
    }

    /**
     * Allocation 1 is linked to visit 10 (automatically, or confirmed when $automatic is false).
     *
     * @param list<\App\Encounter\Application\Matching\MatchingAllocation> $allocations
     * @param list<\App\Encounter\Application\Matching\MatchingEncounter>  $visits
     * @param array<string, true>                                          $rejected
     *
     * @return list<SwapProposal>
     */
    private function plan(array $allocations, array $visits, array $rejected = [], bool $automatic = true): array
    {
        return new SwapPlanner(new MatchingSettings())->plan(
            $allocations,
            $visits,
            $automatic ? [1 => 10] : [],
            [1 => true],
            [10 => true],
            $rejected,
        );
    }
}
