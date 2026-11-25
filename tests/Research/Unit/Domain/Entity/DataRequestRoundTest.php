<?php

declare(strict_types=1);

namespace App\Tests\Research\Unit\Domain\Entity;

use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestRound;
use App\Research\Domain\Enum\RoundKind;
use App\Research\Domain\Enum\RoundOutcome;
use App\Research\Domain\Enum\VoteChoice;
use App\Research\Domain\Exception\DataRequestException;
use App\Research\Domain\ValueObject\DataRequestContent;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Guards of rounds, votes and exclusions on a data extract request (ADR 045).
 */
final class DataRequestRoundTest extends TestCase
{
    public function testARoundRecordsWhoOpenedItAndItsVotes(): void
    {
        [$request, $board] = $this->submitted(2);
        $opener = $board[0];
        $round = $request->openRound(RoundKind::Initial, [1, 2], $opener, new \DateTimeImmutable('2026-12-01 09:00'), new \DateTimeImmutable('2026-12-15 09:00'));

        $round->castVote($board[0], VoteChoice::Approve, null, new \DateTimeImmutable('2026-12-01 10:00'));
        $vote = $round->voteOf($board[0]);

        self::assertSame($request, $round->getRequest());
        self::assertSame($opener, $round->getOpenedBy());
        self::assertEquals(new \DateTimeImmutable('2026-12-01 09:00'), $round->getOpenedAt());
        self::assertNotNull($vote);
        self::assertSame($round, $vote->getRound());
        self::assertEquals(new \DateTimeImmutable('2026-12-01 10:00'), $vote->getCastAt());
        self::assertEquals(new \DateTimeImmutable('2026-12-01 10:00'), $vote->getUpdatedAt());
    }

    public function testAChangedCarriedOverVoteIsNoLongerCarriedOver(): void
    {
        [$request, $board] = $this->submitted(1);
        $round = $request->openRound(RoundKind::AfterRevision, [1], null, new \DateTimeImmutable('2026-12-01 09:00'), new \DateTimeImmutable('2026-12-15 09:00'));
        $round->castVote($board[0], VoteChoice::Approve, null, new \DateTimeImmutable('2026-12-01 09:00'), carriedOver: true);
        self::assertTrue($round->voteOf($board[0])?->isCarriedOver());

        $round->castVote($board[0], VoteChoice::Reject, null, new \DateTimeImmutable('2026-12-02 09:00'));

        $vote = $round->voteOf($board[0]);
        self::assertNotNull($vote);
        self::assertFalse($vote->isCarriedOver());
        self::assertSame(VoteChoice::Reject, $vote->getChoice());
    }

    public function testOnlyEligibleMembersVoteInAnOpenRoundAndAVetoNeedsAReason(): void
    {
        [$request, $board] = $this->submitted(2);
        $round = $request->openRound(RoundKind::Initial, [1], null, new \DateTimeImmutable('2026-12-01 09:00'), new \DateTimeImmutable('2026-12-15 09:00'));
        $at = new \DateTimeImmutable('2026-12-01 10:00');

        $this->assertRefused(static fn () => $round->castVote($board[1], VoteChoice::Approve, null, $at), 'Only eligible members vote in this round.');
        $this->assertRefused(static fn () => $round->castVote($board[0], VoteChoice::Veto, ' ', $at), 'A veto needs a reason.');

        $round->close(RoundOutcome::Rejected, $at);
        $this->assertRefused(static fn () => $round->castVote($board[0], VoteChoice::Approve, null, $at), 'The voting round is closed.');
        $round->removeMember($board[0]);
        self::assertSame(1, $round->getEligibleCount(), 'a closed round keeps its members');
    }

    public function testARoundNeedsEligibleMembersASubmittedVersionAndNoOtherOpenRound(): void
    {
        $at = new \DateTimeImmutable('2026-12-01 09:00');
        $draft = new DataRequest($this->user(1), $this->content(), $at);
        $this->assertRefused(static fn (): DataRequestRound => $draft->openRound(RoundKind::Initial, [2], null, $at, $at), 'The Board votes only on a submitted version.');

        [$request] = $this->submitted(1);
        $this->assertRefused(static fn () => $request->openRound(RoundKind::Initial, [], null, $at, $at), 'A voting round needs at least one eligible member.');
        $request->openRound(RoundKind::Initial, [1], null, $at, $at);
        $this->assertRefused(static fn () => $request->openRound(RoundKind::Initial, [1], null, $at, $at), 'A voting round is still open.');
    }

    public function testAnExclusionIsRecordedOncePerMember(): void
    {
        [$request, $board] = $this->submitted(1);
        $admin = $this->user(9);
        $at = new \DateTimeImmutable('2026-12-01 09:00');

        $request->exclude($board[0], ' Advises the applicant. ', $admin, $at);
        $exclusion = $request->getExclusions()[0];

        self::assertSame($request, $exclusion->getRequest());
        self::assertSame($board[0], $exclusion->getMember());
        self::assertSame('Advises the applicant.', $exclusion->getReason());
        self::assertSame($admin, $exclusion->getRecordedBy());
        self::assertEquals($at, $exclusion->getRecordedAt());
        $this->assertRefused(static fn () => $request->exclude($board[0], 'Again', $admin, $at), 'The member is already excluded.');
    }

    public function testANewVersionStartsOnlyAfterTheCurrentOneWasSubmitted(): void
    {
        $at = new \DateTimeImmutable('2026-12-01 09:00');
        $request = new DataRequest($this->user(1), $this->content(), $at);

        $this->assertRefused(fn () => $request->addVersion($this->content(), null, $at), 'The current version is still open; change it instead.');
    }

    /**
     * @param callable(): mixed $step
     */
    private function assertRefused(callable $step, string $message): void
    {
        try {
            $step();
        } catch (DataRequestException $exception) {
            self::assertSame($message, $exception->getMessage());

            return;
        }

        self::fail('Expected: '.$message);
    }

    /**
     * @return array{DataRequest, list<User>}
     */
    private function submitted(int $members): array
    {
        $request = new DataRequest($this->user(100), $this->content(), new \DateTimeImmutable('2026-11-30 09:00'));
        $request->recordSubmission(new \DateTimeImmutable('2026-11-30 10:00'));
        $board = [];
        for ($id = 1; $id <= $members; ++$id) {
            $board[] = $this->user($id);
        }

        return [$request, $board];
    }

    private function user(int $id): User
    {
        $user = new User();
        new \ReflectionProperty(User::class, 'id')->setValue($user, $id);

        return $user;
    }

    private function content(): DataRequestContent
    {
        return new DataRequestContent(
            title: 'Title',
            summary: 'Summary',
            institution: 'University',
            enrichmentPlanned: false,
            purpose: 'Purpose',
            periodFrom: new \DateTimeImmutable('2024-01-01'),
            periodTo: new \DateTimeImmutable('2024-12-31'),
            population: 'All allocations',
            variables: 'Month',
            granularity: 'Month',
            recipients: 'Team',
            enrichmentDetails: null,
            singleCases: false,
            description: 'Description',
            dataNeeds: 'Data needs',
            responsiblePersons: 'Dr. A',
            conflictsOfInterest: 'none',
            ethics: 'Not needed',
        );
    }
}
