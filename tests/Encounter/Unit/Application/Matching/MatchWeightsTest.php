<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Matching;

use App\Encounter\Application\Matching\FeatureScorer;
use App\Encounter\Application\Matching\MatchingAllocation;
use App\Encounter\Application\Matching\MatchingEncounter;
use App\Encounter\Application\Matching\MatchingSettings;
use App\Encounter\Application\Matching\MatchPlanner;
use App\Encounter\Application\Matching\MatchWeights;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\Sex;
use PHPUnit\Framework\TestCase;

/**
 * Versioned points of the matcher (#1057): v1 keeps the points of ADR 029, v1.1 changes `time.near` and
 * `sex.different` and is the production matcher; a replay may change single points and the "no match"
 * weight under its own label.
 */
final class MatchWeightsTest extends TestCase
{
    public function testVersionOneKeepsThePointsOfAdr029(): void
    {
        $weights = MatchWeights::v1();

        self::assertSame('1.0.0', $weights->version);
        self::assertSame(4, $weights->noMatchPoints);
        self::assertSame(4, $weights->point('time.core'));
        self::assertSame(2, $weights->point('time.near'));
        self::assertSame(-4, $weights->point('sex.different'));
        // 9 points alone: 512 / (512 + 16) = 0.9697.
        self::assertSame(0.9697, $weights->agreementAlone(9));
    }

    public function testVersionOnePointOneChangesOnlyTimeNearAndSexDifferent(): void
    {
        $weights = MatchWeights::v1_1();

        self::assertSame('1.1.0', $weights->version);
        self::assertSame(4, $weights->noMatchPoints);
        self::assertSame(['time.near' => 3, 'sex.different' => -2], array_diff_assoc($weights->points, MatchWeights::v1()->points));
        self::assertSame(array_keys(MatchWeights::POINTS_1), array_keys($weights->points));
    }

    public function testTheProductionMatcherIsVersionOnePointOne(): void
    {
        self::assertSame('1.1.0', MatchWeights::production()->version);
        self::assertSame(MatchPlanner::VERSION, MatchWeights::production()->version);
        self::assertEquals(MatchWeights::production(), new MatchWeights());
        self::assertSame(MatchPlanner::NO_MATCH_POINTS, MatchWeights::production()->noMatchPoints);
        // 8 points alone: 256 / (256 + 16) = 0.9412; 7 points: 128 / 144 = 0.8889; 5 points: 32 / 48 = 0.6667;
        // 3 points: 8 / 24 = 0.3333.
        self::assertSame(0.9412, MatchPlanner::agreementAlone(8));
        self::assertSame(0.8889, MatchPlanner::agreementAlone(7));
        self::assertSame(0.6667, MatchPlanner::agreementAlone(5));
        self::assertSame(0.3333, MatchPlanner::agreementAlone(3));
    }

    public function testVersionsAreFoundByName(): void
    {
        self::assertEquals(MatchWeights::v1(), MatchWeights::named('1.0.0'));
        self::assertEquals(MatchWeights::v1_1(), MatchWeights::named('1.1.0'));

        $this->expectException(\InvalidArgumentException::class);

        MatchWeights::named('0.9.0');
    }

    public function testReplayChangesSinglePointsAndTheNoMatchWeight(): void
    {
        $replay = MatchWeights::v1()->with('try-1', ['time.near' => 3], 5);

        self::assertSame('try-1', $replay->version);
        self::assertSame(3, $replay->point('time.near'));
        self::assertSame(4, $replay->point('time.core'));
        // 9 points against 2^5: 512 / (512 + 32) = 0.9412.
        self::assertSame(0.9412, $replay->agreementAlone(9));
        self::assertSame(4, MatchWeights::v1()->point('time.near') + 2, 'the original stays unchanged');
    }

    public function testRefusesUnknownPoints(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MatchWeights::v1()->with('try-2', ['time.never' => 1]);
    }

    public function testScorerAndPlannerUseTheGivenPoints(): void
    {
        // Arrival 10:00, admission 10:20: time "near". v1: 2 + 2 + 2 + 1 = 7 points; v1.1: 3 + 2 + 2 + 1 = 8.
        $allocation = new MatchingAllocation(1, new \DateTimeImmutable('2026-10-12 10:00'), 'F', 70, false, false);
        $visit = new MatchingEncounter(10, new \DateTimeImmutable('2026-10-12 10:20'), AnchorSource::Admission, Sex::Female, 70, ArrivalMode::Ambulance, ArrivalRoute::Ems, null);

        self::assertSame(2, new FeatureScorer(MatchWeights::v1())->score($allocation, $visit, 20)['time']);
        self::assertSame(3, new FeatureScorer()->score($allocation, $visit, 20)['time']);

        // v1: 128 / (128 + 16) = 0.8889, a proposal; v1.1 (default): 256 / 272 = 0.9412, an automatic link.
        $v1 = new MatchPlanner(new MatchingSettings(), MatchWeights::v1())->plan([$allocation], [$visit]);
        $production = new MatchPlanner(new MatchingSettings())->plan([$allocation], [$visit]);

        self::assertSame([MatchStatus::Proposed, 0.8889], [$v1[0]->status, $v1[0]->score]);
        self::assertSame([MatchStatus::AutoAccepted, 0.9412], [$production[0]->status, $production[0]->score]);
    }

    public function testVersionOneStillDropsADifferentSexThatVersionOnePointOneProposes(): void
    {
        // Same time (+4), age (+2), EMS (+1), male vs female: v1 −4 → 3 points, 0.3333, nothing;
        // v1.1 −2 → 5 points, 0.6667, a proposal.
        $allocation = new MatchingAllocation(1, new \DateTimeImmutable('2026-10-12 10:00'), 'F', 70, false, false);
        $visit = new MatchingEncounter(10, new \DateTimeImmutable('2026-10-12 10:05'), AnchorSource::Admission, Sex::Male, 70, ArrivalMode::Ambulance, ArrivalRoute::Ems, null);

        self::assertSame([], new MatchPlanner(new MatchingSettings(), MatchWeights::v1())->plan([$allocation], [$visit]));

        $production = new MatchPlanner(new MatchingSettings())->plan([$allocation], [$visit]);
        self::assertSame([MatchStatus::Proposed, 0.6667, 5], [$production[0]->status, $production[0]->score, $production[0]->points]);
    }
}
