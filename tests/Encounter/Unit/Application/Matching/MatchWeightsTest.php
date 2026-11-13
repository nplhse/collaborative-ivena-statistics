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
 * Versioned points of the matcher (#1057): v1 keeps the points of ADR 029; a replay may change single
 * points and the "no match" weight under its own label.
 */
final class MatchWeightsTest extends TestCase
{
    public function testVersionOneKeepsThePointsOfAdr029(): void
    {
        $weights = MatchWeights::v1();

        self::assertSame('1.0.0', $weights->version);
        self::assertSame(MatchPlanner::VERSION, $weights->version);
        self::assertSame(4, $weights->noMatchPoints);
        self::assertSame(4, $weights->point('time.core'));
        self::assertSame(-4, $weights->point('sex.different'));
        // 9 points alone: 512 / (512 + 16) = 0.9697.
        self::assertSame(0.9697, $weights->agreementAlone(9));
        self::assertSame(0.9697, MatchPlanner::agreementAlone(9));
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
        // Arrival 10:00, admission 10:20: time "near". v1: 2 + 2 + 2 + 1 = 7 points; with time.near = 4: 9.
        $allocation = new MatchingAllocation(1, new \DateTimeImmutable('2026-10-12 10:00'), 'F', 70, false, false);
        $visit = new MatchingEncounter(10, new \DateTimeImmutable('2026-10-12 10:20'), AnchorSource::Admission, Sex::Female, 70, ArrivalMode::Ambulance, ArrivalRoute::Ems, null);
        $weights = MatchWeights::v1()->with('near-4', ['time.near' => 4]);

        self::assertSame(2, new FeatureScorer()->score($allocation, $visit, 20)['time']);
        self::assertSame(4, new FeatureScorer($weights)->score($allocation, $visit, 20)['time']);

        // v1: 128 / (128 + 16) = 0.8889, a proposal; replay: 512 / 528 = 0.9697, an automatic link.
        $v1 = new MatchPlanner(new MatchingSettings())->plan([$allocation], [$visit]);
        $replay = new MatchPlanner(new MatchingSettings(), $weights)->plan([$allocation], [$visit]);

        self::assertSame([MatchStatus::Proposed, 0.8889], [$v1[0]->status, $v1[0]->score]);
        self::assertSame([MatchStatus::AutoAccepted, 0.9697], [$replay[0]->status, $replay[0]->score]);
    }
}
