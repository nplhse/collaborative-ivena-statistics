<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Security;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Domain\Entity\EncounterAccessGrant;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Whether a stored user may upload encounter files for a hospital, outside a request (ADR 027): the
 * same rule as EncounterVoter::UPLOAD (beta role, and the UPLOAD grant or ROLE_ADMIN). An import is
 * processed as its creator, so the worker checks the creator again before every run.
 */
final readonly class EncounterUploadPermission
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private RoleHierarchyInterface $roleHierarchy,
        private EncounterAccessGrantRepositoryInterface $grantRepository,
    ) {
    }

    public function allows(User $user, Hospital $hospital): bool
    {
        $roles = $this->roleHierarchy->getReachableRoleNames($user->getRoles());
        if (!\in_array(UserRole::ENCOUNTER_BETA, $roles, true)) {
            return false;
        }

        if (\in_array(UserRole::ADMIN, $roles, true)) {
            return true;
        }

        $grant = $this->grantRepository->findForUserAndHospital($user, $hospital);

        return $grant instanceof EncounterAccessGrant && $grant->allows(EncounterGrantAction::Upload);
    }
}
