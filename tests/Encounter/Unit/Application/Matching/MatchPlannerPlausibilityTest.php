<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Matching;

use App\Encounter\Application\Matching\MatchingSettings;
use App\Encounter\Application\Matching\MatchPlanner;
use App\Encounter\Application\Matching\MatchWeights;
use App\Encounter\Application\Matching\PlannedMatch;
use App\Encounter\Application\Plausibility\PlausibilityBonus;
use App\Encounter\Application\Plausibility\PlausibilityLevel;
use App\Encounter\Application\Plausibility\PlausibilityTable;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\PlausibilityCellSource;
use App\Encounter\Domain\Enum\Sex;
use PHPUnit\Framework\TestCase;

/**
 * Plausibility bonus of matcher 1.2.0 (ADR 030 amendment, ADR 033) on hand-checkable inputs. Snapshot 7, minimum 10
 * observations:
 *
 * | Key                    | Cell                            |
 * |------------------------|---------------------------------|
 * | 321 → anaphylaxie      | membership                      |
 * | anaphylaxie × allergie | area, +3                        |
 * | 321 × atemproblem      | exact, +1, 12 observations      |
 * | 321 × kollaps          | exact, +2, 9 observations (too few, and no area cell: 0) |
 *
 * Defaults of {@see MatchingTestData}: a visit at 10:05 to an arrival at 10:00 with the same sex and age by ambulance
 * scores 4 + 2 + 2 + 1 = 9 points. Score = 2^points / (2^4 + Σ 2^points of the candidates).
 */
final class MatchPlannerPlausibilityTest extends TestCase
{
    use MatchingTestData;

    public function testASexMismatchBecomesAnAutomaticLinkThroughTheBonusInPassTwo(): void
    {
        // Pass 1: 4 − 2 + 2 + 1 = 5 points, 32 / 48 = 0.6667, a proposal. Pass 2 adds +3 (area cell): 8 points,
        // 256 / 272 = 0.9412 ≥ 0.90, an automatic link; without the bonus it stays 0.6667 < 0.90: decisive.
        $plan = $this->planner()->plan([self::allocation(1, pzc: 321)], [self::visit(10, sex: Sex::Male, diagram: 'allergie')]);

        self::assertCount(1, $plan);
        $link = $plan[0];
        self::assertSame([MatchStatus::AutoAccepted, 2, 8, 0.9412], [$link->status, $link->pass, $link->points, $link->score]);
        self::assertSame(3, $link->contributions['plausibility']);
        self::assertSame([7, PlausibilityLevel::Area, 0.6667, true], [$link->plausibilitySnapshotId, $link->plausibilityLevel, $link->scoreWithoutBonus, $link->plausibilityDecisive]);
    }

    public function testWithoutSnapshotTheSameCaseStaysAProposalOfPassOne(): void
    {
        $plan = new MatchPlanner(new MatchingSettings())->plan([self::allocation(1, pzc: 321)], [self::visit(10, sex: Sex::Male, diagram: 'allergie')]);

        self::assertSame([MatchStatus::Proposed, 1, 5, 0.6667], [$plan[0]->status, $plan[0]->pass, $plan[0]->points, $plan[0]->score]);
        self::assertArrayNotHasKey('plausibility', $plan[0]->contributions);
        self::assertSame([null, null, false], [$plan[0]->plausibilitySnapshotId, $plan[0]->plausibilityLevel, $plan[0]->plausibilityDecisive]);
    }

    public function testPassOneLinksGetNoBonusEvenForAPlausibleCell(): void
    {
        $plan = $this->planner()->plan([self::allocation(1, pzc: 321)], [self::visit(10, diagram: 'allergie')]);

        self::assertSame([MatchStatus::AutoAccepted, 1, 9, 0.9697], [$plan[0]->status, $plan[0]->pass, $plan[0]->points, $plan[0]->score]);
        self::assertArrayNotHasKey('plausibility', $plan[0]->contributions);
        self::assertSame([null, null, null, false], [$plan[0]->plausibilitySnapshotId, $plan[0]->plausibilityLevel, $plan[0]->scoreWithoutBonus, $plan[0]->plausibilityDecisive]);
    }

    public function testAProposalDecidedByTheBonus(): void
    {
        // Self-presenter (pass 2 only), age 73 (−2): 4 + 2 − 2 − 2 = 2 points, 4 / 20 = 0.2, nothing.
        // With +3: 5 points, 32 / 48 = 0.6667 ≥ 0.50, a proposal; without the bonus 0.2 < 0.50: decisive.
        $plan = $this->planner()->plan([self::allocation(1, pzc: 321)], [self::visit(10, age: 73, mode: ArrivalMode::Self, diagram: 'allergie')]);

        self::assertSame([MatchStatus::Proposed, 2, 5, 0.6667], [$plan[0]->status, $plan[0]->pass, $plan[0]->points, $plan[0]->score]);
        self::assertSame([0.2, true], [$plan[0]->scoreWithoutBonus, $plan[0]->plausibilityDecisive]);
    }

    public function testALinkThatReachesTheThresholdWithoutTheBonusIsNotDecisive(): void
    {
        // Arrival mode "other" (route unknown, not in pass 1): 4 + 2 + 2 + 0 = 8 points, 0.9412 without the bonus;
        // with +3: 11 points, 2048 / 2064 = 0.9922. Automatic either way: not decisive.
        $plan = $this->planner()->plan([self::allocation(1, pzc: 321)], [self::visit(10, mode: ArrivalMode::Other, diagram: 'allergie')]);

        self::assertSame([MatchStatus::AutoAccepted, 2, 11, 0.9922], [$plan[0]->status, $plan[0]->pass, $plan[0]->points, $plan[0]->score]);
        self::assertSame([0.9412, false], [$plan[0]->scoreWithoutBonus, $plan[0]->plausibilityDecisive]);
    }

    public function testAnImplausibleDiagramGivesZeroAndNoPenalty(): void
    {
        // Self-presenter: 4 + 2 + 2 − 2 = 6 points, 64 / 80 = 0.8, a proposal; the diagram has no cell: +0.
        $plan = $this->planner()->plan([self::allocation(1, pzc: 321)], [self::visit(10, mode: ArrivalMode::Self, diagram: 'kopfverletzung')]);

        self::assertSame([MatchStatus::Proposed, 6, 0.8], [$plan[0]->status, $plan[0]->points, $plan[0]->score]);
        self::assertSame(0, $plan[0]->contributions['plausibility']);
        self::assertSame([7, PlausibilityLevel::None, false], [$plan[0]->plausibilitySnapshotId, $plan[0]->plausibilityLevel, $plan[0]->plausibilityDecisive]);
    }

    public function testMissingPzcOrDiagramGivesZero(): void
    {
        $withoutPzc = $this->planner()->plan([self::allocation(1)], [self::visit(10, mode: ArrivalMode::Self, diagram: 'allergie')]);
        $withoutDiagram = $this->planner()->plan([self::allocation(1, pzc: 321)], [self::visit(10, mode: ArrivalMode::Self)]);

        self::assertSame([0, 6], [$withoutPzc[0]->contributions['plausibility'], $withoutPzc[0]->points]);
        self::assertSame([0, 6], [$withoutDiagram[0]->contributions['plausibility'], $withoutDiagram[0]->points]);
    }

    public function testWithoutActiveSnapshotPassTwoRecordsABonusOfZero(): void
    {
        $plan = new MatchPlanner(new MatchingSettings(), MatchWeights::v1_2(), PlausibilityBonus::none())
            ->plan([self::allocation(1, pzc: 321)], [self::visit(10, mode: ArrivalMode::Self, diagram: 'allergie')]);

        self::assertSame([MatchStatus::Proposed, 6, 0.8], [$plan[0]->status, $plan[0]->points, $plan[0]->score]);
        self::assertSame(0, $plan[0]->contributions['plausibility']);
        self::assertSame([null, PlausibilityLevel::None, 0.8, false], [$plan[0]->plausibilitySnapshotId, $plan[0]->plausibilityLevel, $plan[0]->scoreWithoutBonus, $plan[0]->plausibilityDecisive]);
    }

    public function testExactCellWithEnoughObservationsGivesItsOwnPoints(): void
    {
        // 321 × atemproblem: exact +1 (12 observations); self-presenter 6 + 1 = 7 points, 128 / 144 = 0.8889.
        $plan = $this->planner()->plan([self::allocation(1, pzc: 321)], [self::visit(10, mode: ArrivalMode::Self, diagram: 'atemproblem_bei_erwachsenen')]);

        self::assertSame([1, PlausibilityLevel::Exact, 7, 0.8889], [$plan[0]->contributions['plausibility'], $plan[0]->plausibilityLevel, $plan[0]->points, $plan[0]->score]);
    }

    public function testTheBonusIsCappedByTheMatcherVersion(): void
    {
        // A replay with cap +1: the area cell +3 counts +1; self-presenter 6 + 1 = 7 points.
        $capped = new MatchWeights('cap-1', MatchWeights::NO_MATCH_POINTS_1_2, MatchWeights::POINTS_1_2, 1);
        $plan = new MatchPlanner(new MatchingSettings(), $capped, $this->bonus())
            ->plan([self::allocation(1, pzc: 321)], [self::visit(10, mode: ArrivalMode::Self, diagram: 'allergie')]);

        self::assertSame([1, 7], [$plan[0]->contributions['plausibility'], $plan[0]->points]);
    }

    public function testEarlierVersionsIgnoreTheSnapshot(): void
    {
        $plan = new MatchPlanner(new MatchingSettings(), MatchWeights::v1_1(), $this->bonus())
            ->plan([self::allocation(1, pzc: 321)], [self::visit(10, mode: ArrivalMode::Self, diagram: 'allergie')]);

        self::assertSame([6, null, null], [$plan[0]->points, $plan[0]->plausibilitySnapshotId, $plan[0]->plausibilityLevel]);
        self::assertArrayNotHasKey('plausibility', $plan[0]->contributions);
    }

    public function testDecisiveIsJudgedWithTheBonusRemovedFromEveryCandidate(): void
    {
        // Two self-presenters with 6 points each: 64 / (16 + 64 + 64) = 0.4444, nothing. Visit 10 has a plausible
        // diagram (+3): 512 / (16 + 512 + 64) = 0.8649, a proposal; visit 11: 64 / 592 = 0.1081. Without the bonus
        // visit 10 scores 0.4444 < 0.50 again: decisive.
        $plan = $this->planner()->plan(
            [self::allocation(1, pzc: 321)],
            [self::visit(10, mode: ArrivalMode::Self, diagram: 'allergie'), self::visit(11, '10:06', mode: ArrivalMode::Self, diagram: 'kopfverletzung')],
        );

        self::assertSame([[1, 10, 'proposed', 0.8649, 0.4444, true]], array_map(
            static fn (PlannedMatch $m): array => [$m->allocationId, $m->encounterId, $m->status->value, $m->score, $m->scoreWithoutBonus, $m->plausibilityDecisive],
            $plan,
        ));
    }

    public function testAliasDiagramCodesResolveToTheCanonicalCell(): void
    {
        $bonus = new PlausibilityBonus($this->table(), static fn (string $code): string => 'allergische_reaktion' === $code ? 'allergie' : $code);
        $plan = new MatchPlanner(new MatchingSettings(), MatchWeights::v1_2(), $bonus)
            ->plan([self::allocation(1, pzc: 321)], [self::visit(10, mode: ArrivalMode::Self, diagram: 'allergische_reaktion')]);

        self::assertSame([3, PlausibilityLevel::Area, 9], [$plan[0]->contributions['plausibility'], $plan[0]->plausibilityLevel, $plan[0]->points]);
    }

    public function testAnAmbulanceWithIvenaPhysicianScoresPlusOneInVersionOnePointTwo(): void
    {
        // 4 + 2 + 2 + 1 + physician rendezvous +1 = 10 points, 1024 / 1040 = 0.9846; v1.1: −1 → 8 points, 0.9412.
        $allocation = self::allocation(1, withPhysician: true);
        $visit = self::visit(10, mode: ArrivalMode::Ambulance);

        $production = $this->planner()->plan([$allocation], [$visit]);
        $v11 = new MatchPlanner(new MatchingSettings(), MatchWeights::v1_1())->plan([$allocation], [$visit]);

        self::assertSame([10, 1, 0.9846], [$production[0]->points, $production[0]->contributions['physician'], $production[0]->score]);
        self::assertSame([8, -1, 0.9412], [$v11[0]->points, $v11[0]->contributions['physician'], $v11[0]->score]);
    }

    public function testAPatientTransportWithIvenaPhysicianStaysAMismatch(): void
    {
        $plan = $this->planner()->plan([self::allocation(1, withPhysician: true)], [self::visit(10, mode: ArrivalMode::PatientTransport)]);

        // EMS route +1 (patient transport stays an EMS arrival), physician −1: 4 + 2 + 2 + 1 − 1 = 8 points.
        self::assertSame([8, 1, -1], [$plan[0]->points, $plan[0]->contributions['arrival_route'], $plan[0]->contributions['physician']]);
    }

    private function planner(): MatchPlanner
    {
        return new MatchPlanner(new MatchingSettings(), MatchWeights::production(), $this->bonus());
    }

    private function bonus(): PlausibilityBonus
    {
        return new PlausibilityBonus($this->table(), static fn (string $code): string => $code);
    }

    private function table(): PlausibilityTable
    {
        return new PlausibilityTable(
            7,
            10,
            [321 => 'anaphylaxie'],
            [321 => [
                'atemproblem_bei_erwachsenen' => ['points' => 1, 'observations' => 12, 'source' => PlausibilityCellSource::Learned],
                'kollaps' => ['points' => 2, 'observations' => 9, 'source' => PlausibilityCellSource::Learned],
            ]],
            ['anaphylaxie' => ['allergie' => ['points' => 3, 'source' => PlausibilityCellSource::Curated]]],
        );
    }
}
