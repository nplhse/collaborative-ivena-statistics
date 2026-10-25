<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\Voter;

use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * User profiles: participants may view every profile, every other signed-in user only their own.
 *
 * Role hierarchy is respected (e.g. ROLE_ADMIN implies ROLE_PARTICIPANT).
 *
 * @extends Voter<string, User>
 */
final class UserProfileVoter extends Voter
{
    public const string VIEW = 'VIEW_PROFILE';

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    #[\Override]
    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, User::class, true);
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof User && self::VIEW === $attribute;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $viewer = $token->getUser();
        if (!$viewer instanceof User) {
            return false;
        }

        $reachableRoles = $this->roleHierarchy->getReachableRoleNames($token->getRoleNames());
        if (\in_array(UserRole::PARTICIPANT, $reachableRoles, true)) {
            return true;
        }

        return null !== $viewer->getId() && $viewer->getId() === $subject->getId();
    }
}
