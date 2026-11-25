<?php

declare(strict_types=1);

namespace App\Tests\Research\Unit\Domain\Service;

use App\Research\Domain\Enum\RoundKind;
use App\Research\Domain\Enum\RoundOutcome;
use App\Research\Domain\Enum\VoteChoice;
use App\Research\Domain\Service\RoundEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The voting rules of ADR 045 with small, hand-countable examples. A = approve, N = needs revision, R = reject.
 */
final class RoundEvaluatorTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function quorumCases(): iterable
    {
        yield '1 eligible → 1' => [1, 1];
        yield '2 eligible → 2 (4/3 rounded up)' => [2, 2];
        yield '3 eligible → 2' => [3, 2];
        yield '4 eligible → 3 (8/3 rounded up)' => [4, 3];
        yield '6 eligible → 4' => [6, 4];
        yield '7 eligible → 5 (14/3 rounded up)' => [7, 5];
    }

    #[DataProvider('quorumCases')]
    public function testQuorumIsTwoThirdsRoundedUp(int $eligible, int $quorum): void
    {
        self::assertSame($quorum, RoundEvaluator::quorum($eligible));
    }

    /**
     * Five eligible members, deadline passed, no enrichment.
     *
     * @return iterable<string, array{string, RoundOutcome}>
     */
    public static function majorityCases(): iterable
    {
        yield 'AAANR: 3 of 5 approve → approved' => ['AAANR', RoundOutcome::Approved];
        yield 'AANNR: 2 of 5 approve, 4 of 5 approve or revise → revision' => ['AANNR', RoundOutcome::RevisionRequested];
        yield 'AANRR: 2 of 5, 3 of 5 with revisions → revision' => ['AANRR', RoundOutcome::RevisionRequested];
        yield 'ANRRR: 2 of 5 with revisions → rejected' => ['ANRRR', RoundOutcome::Rejected];
        yield 'AANR (4 cast): 2 of 4 is not more than half; 3 of 4 with revision → revision' => ['AANR', RoundOutcome::RevisionRequested];
        yield 'AARR (4 cast): 2 of 4 either way → rejected' => ['AARR', RoundOutcome::Rejected];
        yield 'AAN (3 cast, quorum 4 of 5 not met) → quorum missed' => ['AAN', RoundOutcome::QuorumMissed];
    }

    #[DataProvider('majorityCases')]
    public function testMajorityOfTheVotesCast(string $votes, RoundOutcome $expected): void
    {
        self::assertSame($expected, RoundEvaluator::evaluate(RoundKind::Initial, 5, $this->votes($votes), false, true));
    }

    /**
     * Three eligible members, deadline passed, enrichment with local data planned.
     *
     * @return iterable<string, array{string, RoundOutcome}>
     */
    public static function enrichmentCases(): iterable
    {
        yield 'AAA → approved' => ['AAA', RoundOutcome::Approved];
        yield 'AA (quorum 2 of 3 met, every vote cast approves) → approved' => ['AA', RoundOutcome::Approved];
        yield 'AAN: one revision, no rejection → revision' => ['AAN', RoundOutcome::RevisionRequested];
        yield 'AAR: one rejection → rejected' => ['AAR', RoundOutcome::Rejected];
        yield 'ANR: rejection beats revision → rejected' => ['ANR', RoundOutcome::Rejected];
    }

    #[DataProvider('enrichmentCases')]
    public function testEnrichmentNeedsEveryVoteInFavour(string $votes, RoundOutcome $expected): void
    {
        self::assertSame($expected, RoundEvaluator::evaluate(RoundKind::Initial, 3, $this->votes($votes), true, true));
    }

    public function testAVetoEndsTheRoundAtOnce(): void
    {
        $votes = [VoteChoice::Approve, VoteChoice::Veto];

        self::assertSame(RoundOutcome::Vetoed, RoundEvaluator::evaluate(RoundKind::Initial, 5, $votes, false, false));
        self::assertSame(RoundOutcome::Vetoed, RoundEvaluator::evaluate(RoundKind::AfterRevision, 5, $votes, false, false));
    }

    public function testARoundStaysOpenBeforeItsDeadlineWhileVotesAreMissing(): void
    {
        self::assertNull(RoundEvaluator::evaluate(RoundKind::Initial, 3, $this->votes('AA'), false, false));
    }

    public function testARoundWithoutRevisionEndsOnceEveryoneVoted(): void
    {
        self::assertSame(RoundOutcome::Approved, RoundEvaluator::evaluate(RoundKind::Initial, 3, $this->votes('AAR'), false, false));
        self::assertSame(RoundOutcome::Rejected, RoundEvaluator::evaluate(RoundKind::Restart, 3, $this->votes('ARR'), false, false));
    }

    public function testARoundAfterARevisionWaitsForItsDeadline(): void
    {
        self::assertNull(RoundEvaluator::evaluate(RoundKind::AfterRevision, 3, $this->votes('AAA'), false, false));
        self::assertSame(RoundOutcome::Approved, RoundEvaluator::evaluate(RoundKind::AfterRevision, 3, $this->votes('AAA'), false, true));
    }

    public function testNoVotesAtTheDeadlineMissTheQuorum(): void
    {
        self::assertSame(RoundOutcome::QuorumMissed, RoundEvaluator::evaluate(RoundKind::Initial, 3, [], false, true));
    }

    /**
     * @return list<VoteChoice>
     */
    private function votes(string $letters): array
    {
        return array_map(static fn (string $letter): VoteChoice => match ($letter) {
            'A' => VoteChoice::Approve,
            'N' => VoteChoice::Revision,
            'R' => VoteChoice::Reject,
            default => throw new \InvalidArgumentException($letter),
        }, str_split($letters));
    }
}
