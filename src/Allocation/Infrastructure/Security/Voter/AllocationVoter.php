<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Security\Voter;

use App\Allocation\Application\Service\HospitalPermissionAccess;
use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Explore allocation detail: a participant may VIEW an allocation of a hospital they own or hold a grant for
 * (HospitalPermission::View); administrators may view all. ROLE_USER alone is insufficient.
 * Role hierarchy is respected (e.g. ROLE_ADMIN implies ROLE_PARTICIPANT).
 * See docs/02-architecture/decisions/044-explore-restricted-to-own-hospitals.md.
 *
 * @extends Voter<string, Allocation>
 */
final class AllocationVoter extends Voter
{
    public const string VIEW = 'VIEW';

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly HospitalPermissionAccess $hospitalPermissionAccess,
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

        if (!\in_array(UserRole::PARTICIPANT, $reachableRoles, true)) {
            return false;
        }

        $hospitalId = $subject->getHospital()?->getId();
        if (null === $hospitalId) {
            return $this->hospitalPermissionAccess->isAdministrator($user);
        }

        return $this->hospitalPermissionAccess->hasPermission($user, $hospitalId, HospitalPermission::View);
    }
}
