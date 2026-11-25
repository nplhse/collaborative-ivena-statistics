<?php

declare(strict_types=1);

namespace App\Tests\Research\Integration\Application;

use App\Research\Application\DataRequest\DataRequestDraft;
use App\Research\Application\DataRequest\DataRequestRefused;
use App\Research\Application\DataRequest\DataRequestReviewService;
use App\Research\Application\DataRequest\DataRequestService;
use App\Research\Application\Message\EvaluateDataRequestDeadlinesMessage;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Enum\DataRequestStatus;
use App\Research\Domain\Enum\RoundKind;
use App\Research\Domain\Enum\RoundOutcome;
use App\Research\Domain\Enum\VoteChoice;
use App\Research\Infrastructure\Scheduler\EvaluateDataRequestDeadlinesMessageHandler;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Formal check, voting rounds, exclusions, veto and deadlines of data extract requests (ADR 045), with a Board of
 * three members unless a test says otherwise. The vote period is 14 days.
 */
#[ResetDatabase]
final class DataRequestReviewServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private User $applicant;

    /** @var list<User> */
    private array $board;

    private User $admin;

    #[\Override]
    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-12-01 09:00:00'));
        $this->applicant = UserFactory::new()->asParticipant()->create();
        $this->board = [
            UserFactory::new()->asBoardMember()->create(),
            UserFactory::new()->asBoardMember()->create(),
            UserFactory::new()->asBoardMember()->create(),
        ];
        $this->admin = UserFactory::new()->asAdmin()->create();
    }

    public function testTheFormalCheckOpensARoundForEveryBoardMemberButTheApplicant(): void
    {
        // A Board member who applies is not eligible in their own request.
        $boardApplicant = UserFactory::new()->asBoardMember()->create();
        $request = $this->submitted($boardApplicant);

        $round = $this->review()->openRound($this->board[0], $request);

        self::assertSame(DataRequestStatus::InReview, $request->getStatus());
        self::assertSame(RoundKind::Initial, $round->getKind());
        self::assertSame(3, $round->getEligibleCount());
        self::assertSame(2, $round->getQuorum());
        self::assertFalse($round->isEligible($boardApplicant));
        self::assertEquals(new \DateTimeImmutable('2026-12-15 09:00:00'), $round->getDeadline());
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn (): \App\Research\Domain\Entity\DataRequestRound => $this->review()->openRound($this->applicant, $request)));
    }

    public function testOnlyBoardMembersAndAdministratorsDoTheFormalCheck(): void
    {
        $request = $this->submitted();

        self::assertFalse($this->review()->canCheck(UserFactory::new()->asParticipant()->create(), $request));
        self::assertFalse($this->review()->canCheck($this->applicant, $request));
        self::assertTrue($this->review()->canCheck($this->board[1], $request));
        self::assertTrue($this->review()->canCheck($this->admin, $request));
    }

    public function testAReturnedRequestGoesBackToTheFormalCheckWithANewVersion(): void
    {
        $request = $this->submitted();

        self::assertSame(DataRequestRefused::REASON_REQUIRED, $this->refusal(fn () => $this->review()->returnRequest($this->admin, $request, ' ')));
        $this->review()->returnRequest($this->admin, $request, 'Please name the variables.');
        self::assertSame(DataRequestStatus::Returned, $request->getStatus());
        self::assertSame('Please name the variables.', $request->getHandbackMessage());

        $this->applicantService()->updateDraft($this->applicant, $request, $this->draft('Second version'));
        self::assertSame(2, $request->getCurrentVersion()->getNumber());
        $this->applicantService()->submit($this->applicant, $request);

        self::assertSame(DataRequestStatus::Submitted, $request->getStatus());
        self::assertNull($request->getHandbackMessage());
        self::assertSame([], $request->getRounds(), 'a returned request has no round yet');
    }

    public function testTheRoundEndsOnceEveryoneVoted(): void
    {
        $request = $this->inReview();

        $this->review()->castVote($this->board[0], $request, VoteChoice::Approve, null);
        $this->review()->castVote($this->board[1], $request, VoteChoice::Reject, null);
        // A member may change the vote while the round is open.
        $this->review()->castVote($this->board[1], $request, VoteChoice::Approve, null);
        self::assertSame(DataRequestStatus::InReview, $request->getStatus());

        self::mockTime(new \DateTimeImmutable('2026-12-02 10:00:00'));
        $this->review()->castVote($this->board[2], $request, VoteChoice::Reject, null);

        // AAR: 2 of 3 approve.
        self::assertSame(DataRequestStatus::Approved, $request->getStatus());
        self::assertSame(RoundOutcome::Approved, $request->getLastRound()?->getOutcome());
        self::assertSame($request->getCurrentVersion(), $request->getApprovedVersion());
        self::assertEquals(new \DateTimeImmutable('2026-12-02 10:00:00'), $request->getClosedAt());
    }

    public function testOnlyEligibleMembersVoteAndAVetoNeedsAReason(): void
    {
        $request = $this->inReview();

        self::assertSame(DataRequestRefused::NOT_ELIGIBLE, $this->refusal(fn () => $this->review()->castVote($this->admin, $request, VoteChoice::Approve, null)));
        self::assertSame(DataRequestRefused::REASON_REQUIRED, $this->refusal(fn () => $this->review()->castVote($this->board[0], $request, VoteChoice::Veto, '')));
    }

    public function testAVetoStopsTheRequestUntilAnAdministratorResolvesIt(): void
    {
        $request = $this->inReview();
        $this->review()->castVote($this->board[0], $request, VoteChoice::Veto, 'The ethics vote is missing.');

        self::assertSame(DataRequestStatus::Stalled, $request->getStatus());
        self::assertSame(RoundOutcome::Vetoed, $request->getLastRound()?->getOutcome());
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->review()->resolveStall($this->board[1], $request, DataRequestReviewService::RESOLVE_RESTART, null)));

        self::mockTime(new \DateTimeImmutable('2026-12-03 09:00:00'));
        $this->review()->resolveStall($this->admin, $request, DataRequestReviewService::RESOLVE_RESTART, null);

        self::assertSame(DataRequestStatus::InReview, $request->getStatus());
        $round = $request->getOpenRound();
        self::assertNotNull($round);
        self::assertSame(2, $round->getNumber());
        self::assertSame(RoundKind::Restart, $round->getKind());
        self::assertSame([], $round->getVotes(), 'a restart starts without votes');
        self::assertEquals(new \DateTimeImmutable('2026-12-17 09:00:00'), $round->getDeadline());
    }

    public function testAnAdministratorClosesOrSendsBackAVetoedRequest(): void
    {
        $closed = $this->inReview();
        $this->review()->castVote($this->board[0], $closed, VoteChoice::Veto, 'Out of scope.');
        $this->review()->resolveStall($this->admin, $closed, DataRequestReviewService::RESOLVE_CLOSE, 'The Board will not deal with it.');
        self::assertSame(DataRequestStatus::Rejected, $closed->getStatus());

        $revised = $this->inReview();
        $this->review()->castVote($this->board[0], $revised, VoteChoice::Veto, 'Clarify the recipients.');
        self::assertSame(DataRequestRefused::REASON_REQUIRED, $this->refusal(fn () => $this->review()->resolveStall($this->admin, $revised, DataRequestReviewService::RESOLVE_REVISION, '')));
        $this->review()->resolveStall($this->admin, $revised, DataRequestReviewService::RESOLVE_REVISION, 'Please name the recipients.');
        self::assertSame(DataRequestStatus::RevisionRequested, $revised->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-12-15 09:00:00'), $revised->getRevisionDeadline());
    }

    public function testARevisionCarriesApprovalsOverAndWaitsForTheDeadline(): void
    {
        $request = $this->inReview();
        // ANN: 1 of 3 approve, 3 of 3 with revisions → revision requested.
        $this->review()->castVote($this->board[0], $request, VoteChoice::Approve, 'Fine.');
        $this->review()->castVote($this->board[1], $request, VoteChoice::Revision, 'Coarser age groups.');
        $this->review()->castVote($this->board[2], $request, VoteChoice::Revision, null);
        self::assertSame(DataRequestStatus::RevisionRequested, $request->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-12-15 09:00:00'), $request->getRevisionDeadline());

        self::mockTime(new \DateTimeImmutable('2026-12-10 09:00:00'));
        $this->applicantService()->updateDraft($this->applicant, $request, $this->draft('With age groups'));
        $this->applicantService()->submit($this->applicant, $request);

        $round = $request->getOpenRound();
        self::assertNotNull($round);
        self::assertSame(DataRequestStatus::InReview, $request->getStatus());
        self::assertSame(RoundKind::AfterRevision, $round->getKind());
        self::assertSame(2, $round->getVersion()->getNumber());
        self::assertEquals(new \DateTimeImmutable('2026-12-24 09:00:00'), $round->getDeadline());
        self::assertCount(1, $round->getVotes(), 'only the approval carries over');
        self::assertTrue($round->voteOf($this->board[0])?->isCarriedOver());

        // Everyone voted, but a round after a revision waits for its deadline.
        $this->review()->castVote($this->board[1], $request, VoteChoice::Approve, null);
        $this->review()->castVote($this->board[2], $request, VoteChoice::Approve, null);
        self::assertSame(DataRequestStatus::InReview, $request->getStatus());

        self::mockTime(new \DateTimeImmutable('2026-12-24 09:15:00'));
        $this->runScheduler();

        self::assertSame(DataRequestStatus::Approved, $request->getStatus());
        self::assertSame(2, $request->getApprovedVersion()?->getNumber());
    }

    public function testTheQuorumIsMissedWhenTooFewVoteByTheDeadline(): void
    {
        $request = $this->inReview();
        $this->review()->castVote($this->board[0], $request, VoteChoice::Approve, null);

        self::mockTime(new \DateTimeImmutable('2026-12-15 08:59:00'));
        $this->runScheduler();
        self::assertSame(DataRequestStatus::InReview, $request->getStatus(), 'not due yet');

        self::mockTime(new \DateTimeImmutable('2026-12-15 09:00:00'));
        $this->runScheduler();
        // 1 of 3 voted, quorum 2.
        self::assertSame(DataRequestStatus::Rejected, $request->getStatus());
        self::assertSame(RoundOutcome::QuorumMissed, $request->getLastRound()?->getOutcome());
    }

    public function testARevisionThatIsNotSubmittedInTimeExpires(): void
    {
        $request = $this->inReview();
        foreach ($this->board as $member) {
            $this->review()->castVote($member, $request, VoteChoice::Revision, null);
        }
        self::assertSame(DataRequestStatus::RevisionRequested, $request->getStatus());

        self::mockTime(new \DateTimeImmutable('2026-12-15 09:00:00'));
        $this->runScheduler();

        self::assertSame(DataRequestStatus::Expired, $request->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-12-15 09:00:00'), $request->getClosedAt());
    }

    public function testAnExcludedMemberLeavesTheRoundWithTheirVote(): void
    {
        $request = $this->inReview();
        $this->review()->castVote($this->board[0], $request, VoteChoice::Reject, null);
        $this->review()->castVote($this->board[1], $request, VoteChoice::Approve, null);

        $this->review()->declareExclusion($this->board[0], $request, $this->board[0], 'I advise the applicant.');

        // Two eligible members are left, and only one of them (an approval) has voted: the round stays open.
        $round = $request->getLastRound();
        self::assertNotNull($round);
        self::assertTrue($request->isExcluded($this->board[0]));
        self::assertSame(2, $round->getEligibleCount());
        self::assertNull($round->voteOf($this->board[0]));
        self::assertSame(DataRequestStatus::InReview, $request->getStatus());
        self::assertSame(DataRequestRefused::NOT_ELIGIBLE, $this->refusal(fn () => $this->review()->castVote($this->board[0], $request, VoteChoice::Approve, null)));
        self::assertFalse($this->review()->canCheck($this->board[0], $request));

        // An administrator excludes the member who has not voted; the one remaining approval completes the round.
        $this->review()->declareExclusion($this->admin, $request, $this->board[2], 'Same department as the applicant.');
        self::assertSame(DataRequestStatus::Approved, $request->getStatus());
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->review()->declareExclusion($this->board[1], $request, $this->board[2], 'Not mine to declare.')));
    }

    public function testAWithdrawalEndsTheOpenRound(): void
    {
        $request = $this->inReview();

        $this->applicantService()->withdraw($this->applicant, $request);

        self::assertSame(DataRequestStatus::Withdrawn, $request->getStatus());
        self::assertSame(RoundOutcome::Cancelled, $request->getLastRound()?->getOutcome());
        self::assertSame(DataRequestRefused::NO_OPEN_ROUND, $this->refusal(fn () => $this->review()->castVote($this->board[0], $request, VoteChoice::Approve, null)));
    }

    public function testNoRoundOpensWithoutEligibleMembers(): void
    {
        foreach ($this->board as $member) {
            $member->setRoles(['ROLE_USER']);
        }
        self::getContainer()->get('doctrine')->getManager()->flush();
        $request = $this->submitted();

        self::assertSame(DataRequestRefused::NO_ELIGIBLE_MEMBERS, $this->refusal(fn (): \App\Research\Domain\Entity\DataRequestRound => $this->review()->openRound($this->admin, $request)));
        self::assertSame(DataRequestStatus::Submitted, $request->getStatus());
    }

    public function testRefusedStepsLeaveTheRequestAsItWas(): void
    {
        $request = $this->inReview();
        $participant = UserFactory::new()->asParticipant()->create();

        self::assertSame(DataRequestRefused::TRANSITION_BLOCKED, $this->refusal(fn () => $this->review()->returnRequest($this->admin, $request, 'Too late for a return.')));
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->review()->declareExclusion($this->admin, $request, $participant, 'Not a Board member.')));
        $this->review()->declareExclusion($this->admin, $request, $this->board[0], 'Advises the applicant.');
        self::assertSame(DataRequestRefused::ALREADY_EXCLUDED, $this->refusal(fn () => $this->review()->declareExclusion($this->admin, $request, $this->board[0], 'Again.')));
        self::assertSame(DataRequestStatus::InReview, $request->getStatus());

        $this->review()->castVote($this->board[1], $request, VoteChoice::Veto, 'Stop.');
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->review()->resolveStall($this->admin, $request, 'archive', null)));
        self::assertSame(DataRequestStatus::Stalled, $request->getStatus());
    }

    public function testTheSchedulerLeavesRequestsThatAreNotDueAlone(): void
    {
        $request = $this->inReview();

        self::assertFalse($this->review()->closeDueRound($request), 'the deadline is two weeks away');
        self::assertFalse($this->review()->expireRevision($request), 'no revision was asked for');
        self::assertSame(DataRequestStatus::InReview, $request->getStatus());
    }

    private function runScheduler(): void
    {
        self::getContainer()->get(EvaluateDataRequestDeadlinesMessageHandler::class)(new EvaluateDataRequestDeadlinesMessage());
    }

    private function inReview(): DataRequest
    {
        $request = $this->submitted();
        $this->review()->openRound($this->board[0], $request);

        return $request;
    }

    private function submitted(?User $applicant = null): DataRequest
    {
        $applicant ??= $this->applicant;
        $request = $this->applicantService()->createDraft($applicant, $this->draft('Stroke pathways'));
        $this->applicantService()->submit($applicant, $request);

        return $request;
    }

    /**
     * @param callable(): mixed $step
     */
    private function refusal(callable $step): string
    {
        try {
            $step();
        } catch (DataRequestRefused $refused) {
            return $refused->reason;
        }

        self::fail('The step was not refused.');
    }

    private function draft(string $title): DataRequestDraft
    {
        $draft = new DataRequestDraft();
        $draft->title = $title;
        $draft->summary = 'Summary';
        $draft->institution = 'University';
        $draft->purpose = 'Purpose';
        $draft->periodFrom = new \DateTimeImmutable('2024-01-01');
        $draft->periodTo = new \DateTimeImmutable('2024-12-31');
        $draft->population = 'All allocations';
        $draft->variables = 'Month';
        $draft->granularity = 'Month';
        $draft->recipients = 'Team';
        $draft->description = 'Description';
        $draft->dataNeeds = 'Data needs';
        $draft->responsiblePersons = 'Dr. A';
        $draft->conflictsOfInterest = 'none';
        $draft->ethics = 'Not needed';

        return $draft;
    }

    private function review(): DataRequestReviewService
    {
        return self::getContainer()->get(DataRequestReviewService::class);
    }

    private function applicantService(): DataRequestService
    {
        return self::getContainer()->get(DataRequestService::class);
    }
}
