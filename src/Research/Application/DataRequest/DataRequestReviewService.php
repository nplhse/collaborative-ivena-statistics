<?php

declare(strict_types=1);

namespace App\Research\Application\DataRequest;

use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestRound;
use App\Research\Domain\Enum\DataRequestStatus;
use App\Research\Domain\Enum\RoundKind;
use App\Research\Domain\Enum\RoundOutcome;
use App\Research\Domain\Enum\VoteChoice;
use App\Research\Domain\Exception\DataRequestException;
use App\Research\Domain\Service\RoundEvaluator;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * The Board's side of a data extract request (ADR 045): formal check, voting rounds, exclusions for conflicts of
 * interest, resolving a veto, and the deadlines. The voting rules themselves are in RoundEvaluator.
 */
final readonly class DataRequestReviewService
{
    public const string RESOLVE_CLOSE = 'close';
    public const string RESOLVE_RESTART = 'restart';
    public const string RESOLVE_REVISION = 'revision';

    /** Where the reason of a vote goes: only on the vote (Board), or also as a public or private comment. */
    public const string REASON_ON_VOTE = 'vote';
    public const string REASON_PUBLIC = 'public';
    public const string REASON_PRIVATE = 'private';

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditContext $auditContext,
        private ClockInterface $clock,
        #[Target('data_request.state_machine')]
        private WorkflowInterface $stateMachine,
        private UserRepository $users,
        #[Autowire(param: 'app.research.vote_period_days')]
        private int $votePeriodDays,
    ) {
    }

    /** Board members and administrators who may do the formal check: not the applicant, not excluded. */
    public function canCheck(User $actor, DataRequest $request): bool
    {
        return ($this->isBoardMember($actor) || $this->isAdmin($actor))
            && !$request->isRequestedBy($actor)
            && !$request->isExcluded($actor);
    }

    /**
     * The formal check accepts the request and opens the first voting round.
     *
     * @throws DataRequestRefused
     */
    public function openRound(User $actor, DataRequest $request): DataRequestRound
    {
        $this->assertCanCheck($actor, $request);
        $round = $this->open($request, RoundKind::Initial, $actor, 'open_round');
        $this->flush('research.data_request.round_opened', $request);

        return $round;
    }

    /**
     * The formal check hands the request back to the applicant; the next version goes to the formal check again.
     *
     * @throws DataRequestRefused
     */
    public function returnRequest(User $actor, DataRequest $request, string $message): void
    {
        $this->assertCanCheck($actor, $request);
        $this->assertMessage($message);
        $this->apply($request, 'return');
        $request->handBack($message);
        $this->flush('research.data_request.returned', $request);
    }

    /**
     * @throws DataRequestRefused
     */
    public function castVote(User $member, DataRequest $request, VoteChoice $choice, ?string $reason, string $reasonVisibility = self::REASON_ON_VOTE): void
    {
        $round = $request->getOpenRound();
        if (!$round instanceof DataRequestRound || DataRequestStatus::InReview !== $request->getStatus()) {
            throw new DataRequestRefused(DataRequestRefused::NO_OPEN_ROUND);
        }

        if (!$this->isBoardMember($member) || !$round->isEligible($member) || $request->isExcluded($member)) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ELIGIBLE);
        }

        if ($choice->requiresReason() && '' === trim((string) $reason)) {
            throw new DataRequestRefused(DataRequestRefused::REASON_REQUIRED);
        }

        $now = $this->clock->now();
        try {
            $round->castVote($member, $choice, $reason, $now);
            // The reason becomes a comment only when the member chose its visibility explicitly.
            if (self::REASON_ON_VOTE !== $reasonVisibility && '' !== trim((string) $reason)) {
                $request->addComment($member, (string) $reason, self::REASON_PRIVATE === $reasonVisibility, null, $now, $round->voteOf($member));
            }
        } catch (DataRequestException $exception) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ELIGIBLE, $exception);
        }

        $this->settle($request, $round, false);
        $this->flush(VoteChoice::Veto === $choice ? 'research.data_request.vetoed' : 'research.data_request.voted', $request);
    }

    /**
     * A Board member declares a conflict of interest, or an administrator records one for a member. The member leaves
     * an open round together with their vote; the round may then be complete.
     *
     * @throws DataRequestRefused
     */
    public function declareExclusion(User $actor, DataRequest $request, User $member, string $reason): void
    {
        $self = null !== $actor->getId() && $actor->getId() === $member->getId();
        if ((!$self || !$this->isBoardMember($actor)) && !$this->isAdmin($actor)) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED);
        }

        if (!$this->isBoardMember($member) || $request->getStatus()->isFinal()) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED);
        }

        $this->assertMessage($reason);
        try {
            $request->exclude($member, $reason, $actor, $this->clock->now());
        } catch (DataRequestException $exception) {
            throw new DataRequestRefused(DataRequestRefused::ALREADY_EXCLUDED, $exception);
        }

        $round = $request->getOpenRound();
        if ($round instanceof DataRequestRound) {
            $this->settle($request, $round, false);
        }

        $this->flush('research.data_request.member_excluded', $request);
    }

    /**
     * An administrator resolves a veto: close the request, restart the vote or ask for a revision.
     *
     * @throws DataRequestRefused
     */
    public function resolveStall(User $admin, DataRequest $request, string $action, ?string $message): void
    {
        if (!$this->isAdmin($admin) || DataRequestStatus::Stalled !== $request->getStatus()) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED);
        }

        $now = $this->clock->now();
        switch ($action) {
            case self::RESOLVE_CLOSE:
                $this->apply($request, 'reject');
                $request->handBack($message);
                $request->recordClosing($now);
                break;
            case self::RESOLVE_RESTART:
                $this->open($request, RoundKind::Restart, $admin, 'restart_round');
                break;
            case self::RESOLVE_REVISION:
                $this->assertMessage((string) $message);
                $this->apply($request, 'request_revision');
                $request->requestRevisionUntil($this->deadlineFrom($now), $message);
                break;
            default:
                throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED);
        }

        $this->flush('research.data_request.veto_resolved', $request);
    }

    /**
     * After the applicant submitted the revision the Board asked for: a new round on the new version. Approvals of
     * the previous round carry over for members who are still eligible; they can change them until the deadline.
     *
     * @throws DataRequestRefused
     */
    public function openRoundAfterRevision(DataRequest $request): void
    {
        $previous = $request->getLastRound();
        $round = $this->open($request, RoundKind::AfterRevision, null, 'submit_revision');

        if ($previous instanceof DataRequestRound) {
            $now = $this->clock->now();
            foreach ($previous->getVotes() as $vote) {
                $voter = $vote->getVoter();
                if (VoteChoice::Approve === $vote->getChoice() && $voter instanceof User && $round->isEligible($voter)) {
                    $round->castVote($voter, VoteChoice::Approve, $vote->getReason(), $now, carriedOver: true);
                }
            }
        }
    }

    /** Ends an open round when its request is withdrawn. */
    public function cancelOpenRound(DataRequest $request): void
    {
        $request->getOpenRound()?->close(RoundOutcome::Cancelled, $this->clock->now());
    }

    /**
     * Scheduler job, for a request whose round deadline passed: evaluates the round.
     *
     * @return bool whether a round was closed
     */
    public function closeDueRound(DataRequest $request): bool
    {
        $round = $request->getOpenRound();
        if (!$round instanceof DataRequestRound || DataRequestStatus::InReview !== $request->getStatus() || $round->getDeadline() > $this->clock->now()) {
            return false;
        }

        $this->settle($request, $round, true);
        $this->flush('research.data_request.round_closed', $request);

        return true;
    }

    /**
     * Scheduler job, for a request whose revision deadline passed: the request expires.
     *
     * @return bool whether the request expired
     */
    public function expireRevision(DataRequest $request): bool
    {
        $deadline = $request->getRevisionDeadline();
        if (!$deadline instanceof \DateTimeImmutable || $deadline > $this->clock->now() || !$this->stateMachine->can($request, 'expire')) {
            return false;
        }

        $this->stateMachine->apply($request, 'expire');
        $request->recordClosing($this->clock->now());
        $this->flush('research.data_request.expired', $request);

        return true;
    }

    /**
     * @throws DataRequestRefused
     */
    private function open(DataRequest $request, RoundKind $kind, ?User $actor, string $transition): DataRequestRound
    {
        $eligible = [];
        foreach ($this->users->findEnabledVerifiedUsersWithRoles([UserRole::BOARD_MEMBER]) as $member) {
            $id = $member->getId();
            if (null !== $id && !$request->isRequestedBy($member) && !$request->isExcluded($member)) {
                $eligible[] = $id;
            }
        }

        if ([] === $eligible) {
            throw new DataRequestRefused(DataRequestRefused::NO_ELIGIBLE_MEMBERS);
        }

        $this->apply($request, $transition);
        $now = $this->clock->now();

        return $request->openRound($kind, $eligible, $actor, $now, $this->deadlineFrom($now));
    }

    /** Closes the round when the rules say so and moves the request on. */
    private function settle(DataRequest $request, DataRequestRound $round, bool $deadlinePassed): void
    {
        $outcome = RoundEvaluator::evaluate(
            $round->getKind(),
            $round->getEligibleCount(),
            $round->choices(),
            $round->getVersion()->isEnrichmentPlanned(),
            $deadlinePassed,
        );
        if (!$outcome instanceof RoundOutcome) {
            return;
        }

        $now = $this->clock->now();
        $round->close($outcome, $now);
        match ($outcome) {
            RoundOutcome::Approved => $this->approve($request, $round, $now),
            RoundOutcome::RevisionRequested => $this->askForRevision($request, $now),
            RoundOutcome::Rejected, RoundOutcome::QuorumMissed => $this->reject($request, $now),
            RoundOutcome::Vetoed => $this->stateMachine->apply($request, 'veto'),
            RoundOutcome::Cancelled => null,
        };
    }

    private function approve(DataRequest $request, DataRequestRound $round, \DateTimeImmutable $now): void
    {
        $this->stateMachine->apply($request, 'approve');
        $request->recordApproval($round->getVersion(), $now);
    }

    private function askForRevision(DataRequest $request, \DateTimeImmutable $now): void
    {
        $this->stateMachine->apply($request, 'request_revision');
        $request->requestRevisionUntil($this->deadlineFrom($now));
    }

    private function reject(DataRequest $request, \DateTimeImmutable $now): void
    {
        $this->stateMachine->apply($request, 'reject');
        $request->recordClosing($now);
    }

    private function deadlineFrom(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(\sprintf('+%d days', $this->votePeriodDays));
    }

    /**
     * @throws DataRequestRefused
     */
    private function assertCanCheck(User $actor, DataRequest $request): void
    {
        if (!$this->canCheck($actor, $request)) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED);
        }
    }

    /**
     * @throws DataRequestRefused
     */
    private function assertMessage(string $message): void
    {
        if ('' === trim($message)) {
            throw new DataRequestRefused(DataRequestRefused::REASON_REQUIRED);
        }
    }

    /**
     * @throws DataRequestRefused
     */
    private function apply(DataRequest $request, string $transition): void
    {
        if (!$this->stateMachine->can($request, $transition)) {
            throw new DataRequestRefused(DataRequestRefused::TRANSITION_BLOCKED);
        }

        $this->stateMachine->apply($request, $transition);
    }

    private function isBoardMember(User $user): bool
    {
        return \in_array(UserRole::BOARD_MEMBER, $user->getRoles(), true);
    }

    private function isAdmin(User $user): bool
    {
        return \in_array(UserRole::ADMIN, $user->getRoles(), true);
    }

    /**
     * @throws DataRequestRefused when someone else changed the request in the meantime
     */
    private function flush(string $intent, DataRequest $request): void
    {
        $this->auditContext->beginIntent($intent, ['data_request_id' => $request->getId()]);
        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException $exception) {
            throw new DataRequestRefused(DataRequestRefused::CHANGED_MEANWHILE, $exception);
        } finally {
            $this->auditContext->endIntent();
        }
    }
}
