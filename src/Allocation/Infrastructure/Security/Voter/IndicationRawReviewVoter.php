<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Security\Voter;

use App\Allocation\Domain\Entity\IndicationRaw;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * @extends Voter<string, IndicationRaw|null>
 */
final class IndicationRawReviewVoter extends Voter
{
    public const string VIEW = 'INDICATION_RAW_REVIEW_VIEW';

    public const string EDIT_MATCH = 'INDICATION_RAW_REVIEW_EDIT_MATCH';

    public const string REVIEW = 'INDICATION_RAW_REVIEW';

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!\in_array($attribute, [self::VIEW, self::EDIT_MATCH, self::REVIEW], true)) {
            return false;
        }

        return null === $subject || $subject instanceof IndicationRaw;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($token),
            self::EDIT_MATCH => $this->hasBothReviewerRoles($token),
            self::REVIEW => $this->canReview($token, $subject instanceof IndicationRaw ? $subject : null),
            default => false,
        };
    }

    private function canView(TokenInterface $token): bool
    {
        return $this->isGranted($token, UserRole::PARTICIPANT);
    }

    private function hasBothReviewerRoles(TokenInterface $token): bool
    {
        return $this->isGranted($token, UserRole::PARTICIPANT)
            && $this->isGranted($token, UserRole::REVIEW_INDICATIONS);
    }

    private function canReview(TokenInterface $token, ?IndicationRaw $raw): bool
    {
        if (!$this->hasBothReviewerRoles($token)) {
            return false;
        }

        if ($this->isGranted($token, UserRole::ADMIN)) {
            return true;
        }

        if (!$raw instanceof IndicationRaw) {
            return true;
        }

        $firstMatcher = $raw->getFirstMatchedBy();
        if (!$firstMatcher instanceof User) {
            return true;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $firstMatcherId = $firstMatcher->getId();
        $userId = $user->getId();

        return null === $firstMatcherId || null === $userId || $firstMatcherId !== $userId;
    }

    private function isGranted(TokenInterface $token, string $role): bool
    {
        $reachableRoles = $this->roleHierarchy->getReachableRoleNames($token->getRoleNames());

        return \in_array($role, $reachableRoles, true);
    }
}
