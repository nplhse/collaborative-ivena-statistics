<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Security\Voter;

use App\Research\Application\DataRequest\DataRequestCommentService;
use App\Research\Application\DataRequest\DataRequestReleaseService;
use App\Research\Application\DataRequest\DataRequestReviewService;
use App\Research\Application\DataRequest\DataRequestService;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Enum\DataRequestStatus;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Who may see and change a data extract request (ADR 045).
 *
 * - VIEW: participants see every request that was submitted at least once; a draft only its applicant.
 * - VIEW_INTERNAL: the internal details and the recipients: the applicant, Board members and administrators, the
 *   latter two only once the request was submitted.
 * - EDIT, SUBMIT, WITHDRAW: only the applicant, and only where the state machine allows it. After a return or a
 *   requested revision, editing creates the next version, which the applicant then submits.
 * - CHECK: the formal check of a submitted request, by Board members and administrators who are neither the
 *   applicant nor excluded.
 * - VOTE: eligible Board members while a round is open. VIEW_VOTES: single votes, for Board members who are neither
 *   the applicant nor excluded, and for administrators.
 * - EXCLUDE_SELF: a Board member declares a conflict of interest. RECORD_EXCLUSION, RESOLVE: administrators.
 *   Exclusions only while the request is under review, that is submitted and not yet decided.
 * - COMMENT: who may write at least one visibility level (DataRequestCommentService).
 * - MANAGE_QUERIES: administrators, once the request is approved.
 * - RELEASE: administrators, once every query passes the check and no other release is open
 *   (DataRequestReleaseService); starting and downloading the export the service decides per release.
 *
 * @extends Voter<string, DataRequest>
 */
final class DataRequestVoter extends Voter
{
    public const string VIEW = 'DATA_REQUEST_VIEW';
    public const string VIEW_INTERNAL = 'DATA_REQUEST_VIEW_INTERNAL';
    public const string EDIT = 'DATA_REQUEST_EDIT';
    public const string SUBMIT = 'DATA_REQUEST_SUBMIT';
    public const string WITHDRAW = 'DATA_REQUEST_WITHDRAW';
    public const string CHECK = 'DATA_REQUEST_CHECK';
    public const string VOTE = 'DATA_REQUEST_VOTE';
    public const string VIEW_VOTES = 'DATA_REQUEST_VIEW_VOTES';
    public const string EXCLUDE_SELF = 'DATA_REQUEST_EXCLUDE_SELF';
    public const string RECORD_EXCLUSION = 'DATA_REQUEST_RECORD_EXCLUSION';
    public const string RESOLVE = 'DATA_REQUEST_RESOLVE';
    public const string COMMENT = 'DATA_REQUEST_COMMENT';
    public const string MANAGE_QUERIES = 'DATA_REQUEST_MANAGE_QUERIES';
    public const string RELEASE = 'DATA_REQUEST_RELEASE';

    private const array ATTRIBUTES = [
        self::VIEW, self::VIEW_INTERNAL, self::EDIT, self::SUBMIT, self::WITHDRAW, self::CHECK, self::VOTE,
        self::VIEW_VOTES, self::EXCLUDE_SELF, self::RECORD_EXCLUSION, self::RESOLVE, self::COMMENT, self::MANAGE_QUERIES,
        self::RELEASE,
    ];

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        #[Target('data_request.state_machine')]
        private readonly WorkflowInterface $stateMachine,
        private readonly DataRequestReviewService $review,
        private readonly DataRequestCommentService $comments,
        private readonly DataRequestReleaseService $releases,
    ) {
    }

    #[\Override]
    public function supportsAttribute(string $attribute): bool
    {
        return \in_array($attribute, self::ATTRIBUTES, true);
    }

    #[\Override]
    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, DataRequest::class, true);
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, self::ATTRIBUTES, true) && $subject instanceof DataRequest;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof DataRequest) {
            return false;
        }

        $own = $subject->isRequestedBy($user);
        $status = $subject->getStatus();
        $board = $this->accessDecisionManager->decide($token, [UserRole::BOARD_MEMBER]);
        $admin = $this->accessDecisionManager->decide($token, [UserRole::ADMIN]);

        return match ($attribute) {
            self::VIEW => $own || ($subject->wasSubmitted() && $this->accessDecisionManager->decide($token, [UserRole::PARTICIPANT])),
            self::VIEW_INTERNAL => $own || ($subject->wasSubmitted() && ($board || $admin)),
            self::EDIT => $own && $status->isEditable(),
            self::SUBMIT => $own && !$subject->getCurrentVersion()->isSubmitted() && $this->canSubmit($subject),
            self::WITHDRAW => $own && $this->stateMachine->can($subject, 'withdraw'),
            self::CHECK => DataRequestStatus::Submitted === $status && $this->review->canCheck($user, $subject),
            self::VOTE => $board && DataRequestStatus::InReview === $status && !$subject->isExcluded($user)
                && true === $subject->getOpenRound()?->isEligible($user),
            self::VIEW_VOTES => ($board && !$own && !$subject->isExcluded($user)) || $admin,
            self::EXCLUDE_SELF => $board && !$own && $status->isUnderReview() && !$subject->isExcluded($user),
            self::RECORD_EXCLUSION => $admin && $status->isUnderReview(),
            self::RESOLVE => $admin && DataRequestStatus::Stalled === $status,
            self::COMMENT => $this->comments->canComment($user, $subject),
            self::MANAGE_QUERIES => $admin && DataRequestStatus::Approved === $status,
            self::RELEASE => $admin && $this->releases->canRelease($user, $subject),
            default => false,
        };
    }

    private function canSubmit(DataRequest $request): bool
    {
        $transition = DataRequestService::submitTransition($request->getStatus());

        return null !== $transition && $this->stateMachine->can($request, $transition);
    }
}
