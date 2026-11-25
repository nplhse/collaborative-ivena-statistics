<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Security\Voter;

use App\Research\Domain\Entity\DataRequest;
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
 * - EDIT, SUBMIT, WITHDRAW: only the applicant, and only where the state machine allows it.
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

    private const array ATTRIBUTES = [self::VIEW, self::VIEW_INTERNAL, self::EDIT, self::SUBMIT, self::WITHDRAW];

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        #[Target('data_request.state_machine')]
        private readonly WorkflowInterface $stateMachine,
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

        return match ($attribute) {
            self::VIEW => $own || ($subject->wasSubmitted() && $this->accessDecisionManager->decide($token, [UserRole::PARTICIPANT])),
            self::VIEW_INTERNAL => $own || ($subject->wasSubmitted() && $this->isBoardOrAdmin($token)),
            self::EDIT => $own && $subject->getStatus()->isEditable() && !$subject->getCurrentVersion()->isSubmitted(),
            self::SUBMIT => $own && $this->stateMachine->can($subject, 'submit'),
            self::WITHDRAW => $own && $this->stateMachine->can($subject, 'withdraw'),
            default => false,
        };
    }

    private function isBoardOrAdmin(TokenInterface $token): bool
    {
        return $this->accessDecisionManager->decide($token, [UserRole::BOARD_MEMBER])
            || $this->accessDecisionManager->decide($token, [UserRole::ADMIN]);
    }
}
