<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Security\Voter;

use App\Allocation\Domain\Entity\Allocation;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Explore allocation detail: any ROLE_PARTICIPANT may VIEW any allocation (collaborative by design).
 *
 * Not scoped to HospitalPermission::View. ROLE_USER alone is insufficient.
 * Role hierarchy is respected (e.g. ROLE_ADMIN implies ROLE_PARTICIPANT).
 * See docs/02-architecture/decisions/011-collaborative-explore-allocation-visibility.md.
 *
 * @extends Voter<string, Allocation>
 */
final class AllocationVoter extends Voter
{
    public const string VIEW = 'VIEW';

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    #[\Override]
    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, Allocation::class, true);
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Allocation && self::VIEW === $attribute;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $reachableRoles = $this->roleHierarchy->getReachableRoleNames($token->getRoleNames());

        return \in_array(UserRole::PARTICIPANT, $reachableRoles, true);
    }
}
