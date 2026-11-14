<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Matching;

use App\Encounter\Application\Matching\MatchingSettings;
use App\Encounter\Application\Matching\MatchPlanner;
use App\Encounter\Application\Matching\PlannedMatch;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\Sex;
use PHPUnit\Framework\TestCase;

/**
 * Score, passes, conflict, reservation and gap filling of the production matcher (v1.2 without plausibility snapshot, ADR 030) on
 * hand-checkable inputs. Score = 2^points / (2^4 "no match" + sum of 2^points of the allocation's candidates).
 */
final class MatchPlannerTest extends TestCase
{
    use MatchingTestData;

    public function testASingleTypicalCandidateIsLinkedAutomatically(): void
    {
        $plan = $this->planner()->plan([self::allocation(1)], [self::visit(10)]);

        self::assertCount(1, $plan);
        $link = $plan[0];
        self::assertSame(MatchStatus::AutoAccepted, $link->status);
        self::assertSame([1, 10, 1], [$link->allocationId, $link->encounterId, $link->pass]);
        // 2^9 / (2^4 + 2^9) = 512 / 528
        self::assertSame(0.9697, $link->score);
        self::assertSame(9, $link->points);
        self::assertSame(5, $link->timeDifferenceMinutes);
        self::assertSame(AnchorSource::Admission, $link->timeAnchor);
        self::assertFalse($link->conflict);
    }

    public function testCompetingCandidatesShareTheScore(): void
    {
        // Visit 10: 9 points; visit 11 at 10:20 (time +3) and one year older (age +1): 7 points.
        // 512 / (16 + 512 + 128) = 0.7805 and 128 / 656 = 0.1951: a proposal, nothing automatic.
        $plan = $this->planner()->plan([self::allocation(1)], [self::visit(10), self::visit(11, '10:20', age: 71)]);

        self::assertSame([[1, 10, 'proposed', 0.7805]], $this->rows($plan));
    }

    public function testTwoEqualCandidatesBothScoreLow(): void
    {
        // 512 / (16 + 512 + 512) = 0.4923 each: below the proposal threshold.
        self::assertSame([], $this->planner()->plan([self::allocation(1)], [self::visit(10), self::visit(11, '10:06')]));
    }

    public function testAWeakSingleCandidateIsOnlyProposed(): void
    {
        // Age 72 (0) at 10:20 (+3): 3 + 2 + 0 + 1 = 6 points, 64 / (16 + 64) = 0.8.
        $plan = $this->planner()->plan([self::allocation(1)], [self::visit(10, '10:20', age: 72)]);

        self::assertSame([[1, 10, 'proposed', 0.8]], $this->rows($plan));
        self::assertSame(1, $plan[0]->pass);
    }

    public function testANearPairWithEverythingElseMatchingIsLinkedAutomatically(): void
    {
        // 10:20 is "near" (+16 … +30): 3 + 2 + 2 + 1 = 8 points, 256 / (16 + 256) = 0.9412 (v1: 7 points, 0.8889).
        $plan = $this->planner()->plan([self::allocation(1)], [self::visit(10, '10:20')]);

        self::assertSame([[1, 10, 'auto_accepted', 0.9412]], $this->rows($plan));
        self::assertSame(8, $plan[0]->points);
    }

    public function testADifferentSexIsOnlyProposed(): void
    {
        // Male vs female (−2): 4 − 2 + 2 + 1 = 5 points, 32 / (16 + 32) = 0.6667 (v1: −4, 3 points, 0.3333).
        $plan = $this->planner()->plan([self::allocation(1)], [self::visit(10, sex: Sex::Male)]);

        self::assertSame([[1, 10, 'proposed', 0.6667]], $this->rows($plan));
        self::assertSame(-2, $plan[0]->contributions['sex']);
    }

    public function testBelowTheProposalThresholdNothingIsWritten(): void
    {
        // Male vs female (−2) and age 72 (0): 4 − 2 + 0 + 1 = 3 points, 8 / 24 = 0.3333.
        self::assertSame([], $this->planner()->plan([self::allocation(1)], [self::visit(10, sex: Sex::Male, age: 72)]));
    }

    public function testPassOneTakesOnlyEmsVisitsAndPassTwoTheRest(): void
    {
        // A self-presenter is no pass-1 candidate. Pass 2: 4 + 2 + 2 − 2 = 6 points, 64 / 80 = 0.8.
        $plan = $this->planner()->plan([self::allocation(1)], [self::visit(10, mode: ArrivalMode::Self)]);

        self::assertSame([[1, 10, 'proposed', 0.8]], $this->rows($plan));
        self::assertSame(2, $plan[0]->pass);
    }

    public function testPassOneWindowAndAgeAreLimitsPassTwoWidensThem(): void
    {
        // 50 minutes after the arrival (outside +45) and 4 years older (outside ±2): pass 2 only.
        // −2 + 2 − 2 + 1 = −1 point: 0.5 / 16.5 = 0.0303, nothing.
        self::assertSame([], $this->planner()->plan([self::allocation(1)], [self::visit(10, '10:50', age: 74)]));

        // 21 minutes before (outside −20), same age: −2 + 2 + 2 + 1 = 3 points in pass 2, 8 / 24 = 0.3333.
        self::assertSame([], $this->planner()->plan([self::allocation(1)], [self::visit(10, '09:39')]));

        // 91 minutes after or 6 years apart: no candidate in any pass.
        self::assertSame([], $this->planner()->plan([self::allocation(1)], [self::visit(10, '11:31'), self::visit(11, age: 76)]));
    }

    public function testPassTwoLinksWhatPassOneCannotSee(): void
    {
        // Age 74 (outside ±2): only pass 2, time +4, sex +2, age −2, route +1, physician +2 = 7 points,
        // 128 / 144 = 0.8889: proposal. With a work accident on both sides (+2): 9 points, 0.9697: link.
        $allocation = self::allocation(1, withPhysician: true, workAccident: true);
        $visit = self::visit(10, age: 74, mode: ArrivalMode::PhysicianVehicle, workAccident: true);

        $plan = $this->planner()->plan([$allocation], [$visit]);

        self::assertSame([[1, 10, 'auto_accepted', 0.9697]], $this->rows($plan));
        self::assertSame(2, $plan[0]->pass);
        // Pass 2 of 1.2.0 records the plausibility bonus, 0 without snapshot.
        self::assertSame(['time' => 4, 'sex' => 2, 'age' => -2, 'arrival_route' => 1, 'physician' => 2, 'work_accident' => 2, 'plausibility' => 0], $plan[0]->contributions);
    }

    public function testTheSubstituteAnchorIsMarked(): void
    {
        $plan = $this->planner()->plan([self::allocation(1)], [self::visit(10, '10:12', anchorSource: AnchorSource::Assessment)]);

        self::assertSame(AnchorSource::Assessment, $plan[0]->timeAnchor);
        self::assertSame(12, $plan[0]->timeDifferenceMinutes);
    }

    public function testTwoAllocationsReachingTheThresholdForOneVisitAreAConflict(): void
    {
        // Each allocation has visit 10 as only candidate: 0.9697 for both. Neither becomes a link,
        // both go to review marked as conflict, and the visit stays free for allocation 3 only through
        // the same check: allocation 3 has visit 10 and 11, so it shares its score and links visit 11.
        $plan = $this->planner()->plan(
            [self::allocation(1), self::allocation(2, '10:02'), self::allocation(3, '12:00', sex: 'M')],
            [self::visit(10), self::visit(11, '12:04', sex: Sex::Male)],
        );

        self::assertSame([
            [3, 11, 'auto_accepted', 0.9697],
            [1, 10, 'proposed', 0.9697],
            [2, 10, 'proposed', 0.9697],
        ], $this->rows($plan));
        self::assertSame([false, true, true], array_map(static fn (PlannedMatch $m): bool => $m->conflict, $plan));
        self::assertSame([1, 1, 1], array_map(static fn (PlannedMatch $m): int => $m->pass, $plan));
    }

    public function testALinkReservesBothSidesForLaterPasses(): void
    {
        // Pass 1 links allocation 1 with visit 10. Allocation 2 (66 years) is 4 years from visit 10, so it
        // could take visit 10 only in pass 2, where visit 10 is reserved; its only candidate is
        // self-presenter 11: time +4, sex +2, age +2, route −2 = 6 points, 64 / 80 = 0.8.
        $plan = $this->planner()->plan(
            [self::allocation(1), self::allocation(2, age: 66)],
            [self::visit(10), self::visit(11, age: 66, mode: ArrivalMode::Self)],
        );

        self::assertSame([[1, 10, 'auto_accepted', 0.9697], [2, 11, 'proposed', 0.8]], $this->rows($plan));
    }

    public function testExistingLinksAndRejectionsAreRespected(): void
    {
        $allocations = [self::allocation(1), self::allocation(2, '14:00')];
        $visits = [self::visit(10), self::visit(11, '14:03'), self::visit(12, '14:05')];

        // Allocation 1 is linked already (with some visit), visit 11 too: only allocation 2 is matched,
        // and only with visit 12. Pair 2–12 is rejected: nothing is proposed again.
        self::assertSame([[2, 12, 'auto_accepted', 0.9697]], $this->rows($this->planner()->plan($allocations, $visits, [1 => true], [11 => true])));
        self::assertSame([], $this->planner()->plan($allocations, $visits, [1 => true], [11 => true], ['2:12' => true]));
    }

    public function testARejectedPairDoesNotCompeteAnyMore(): void
    {
        // Visits 10 and 11 are equally good (0.4923 each). After rejecting 1–11, visit 10 is the only candidate.
        $plan = $this->planner()->plan([self::allocation(1)], [self::visit(10), self::visit(11, '10:06')], rejectedPairs: ['1:11' => true]);

        self::assertSame([[1, 10, 'auto_accepted', 0.9697]], $this->rows($plan));
    }

    public function testThresholdsComeFromTheSettings(): void
    {
        $strict = new MatchPlanner(new MatchingSettings(autoAcceptThreshold: 0.98, proposalThreshold: 0.9));

        self::assertSame([[1, 10, 'proposed', 0.9697]], $this->rows($strict->plan([self::allocation(1)], [self::visit(10)])));
    }

    private function planner(): MatchPlanner
    {
        return new MatchPlanner(new MatchingSettings());
    }

    /**
     * @param list<PlannedMatch> $plan
     *
     * @return list<array{int, int, string, float}>
     */
    private function rows(array $plan): array
    {
        return array_map(static fn (PlannedMatch $m): array => [$m->allocationId, $m->encounterId, $m->status->value, $m->score], $plan);
    }
}
