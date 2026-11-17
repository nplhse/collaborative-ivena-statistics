<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Allocation\Application\Service\HospitalPermissionAccess;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Import\Domain\Enum\ImportType;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Where a user may upload which import type (#1351). The upload form, the controller, the
 * `IMPORT_CREATE` voter and every link to the form ask this one place, so they cannot disagree.
 *
 * - Allocation and closure lists: hospitals with `HospitalPermission::Import` (owners and admins included).
 * - Closure lists additionally need the opt-in role `ROLE_CLOSURE_BETA` while they are a beta.
 * - Types of other contexts (`import.type_extension`): `isAvailableTo()` and `uploadHospitalIds()`.
 * - Nothing without `ROLE_PARTICIPANT`, the boundary of the import pages.
 */
final class ImportUploadTargets implements ResetInterface
{
    /** @var array<int, array<string, list<int>>> */
    private array $byUser = [];

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private readonly HospitalPermissionAccess $hospitalPermissionAccess,
        private readonly ImportTypeExtensionRegistry $typeExtensions,
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    /**
     * Hospital IDs the user may upload to, by import type value, in the order of the upload form.
     * Types without any hospital are left out.
     *
     * @return array<string, list<int>>
     */
    public function hospitalIdsByType(User $user): array
    {
        $userId = $user->getId();
        if (null !== $userId && isset($this->byUser[$userId])) {
            return $this->byUser[$userId];
        }

        $targets = $this->resolve($user);
        if (null !== $userId) {
            $this->byUser[$userId] = $targets;
        }

        return $targets;
    }

    /**
     * Whether the user may upload at all, a given type, to a given hospital, or both together.
     */
    public function canUpload(User $user, ?ImportType $type = null, ?int $hospitalId = null): bool
    {
        $targets = $this->hospitalIdsByType($user);
        if ($type instanceof ImportType) {
            $targets = \array_key_exists($type->value, $targets) ? [$targets[$type->value]] : [];
        }

        if (null === $hospitalId) {
            return [] !== $targets;
        }

        return array_any($targets, static fn (array $hospitalIds): bool => \in_array($hospitalId, $hospitalIds, true));
    }

    #[\Override]
    public function reset(): void
    {
        $this->byUser = [];
    }

    /**
     * @return array<string, list<int>>
     */
    private function resolve(User $user): array
    {
        if (!\in_array(UserRole::PARTICIPANT, $this->roleHierarchy->getReachableRoleNames($user->getRoles()), true)) {
            return [];
        }

        $targets = [];
        $importHospitalIds = $this->hospitalPermissionAccess->resolveHospitalIdsWithPermission($user, HospitalPermission::Import);
        if ([] !== $importHospitalIds) {
            $targets[ImportType::ALLOCATION->value] = $importHospitalIds;

            // Opt-in beta role, not part of the role hierarchy (#1350 takes closure lists out of the beta).
            if (\in_array(UserRole::CLOSURE_BETA, $user->getRoles(), true)) {
                $targets[ImportType::CLOSURE->value] = $importHospitalIds;
            }
        }

        foreach ($this->typeExtensions->availableTo($user) as $extension) {
            $hospitalIds = array_values(array_unique($extension->uploadHospitalIds($user)));
            if ([] !== $hospitalIds) {
                $targets[$extension::importType()->value] = $hospitalIds;
            }
        }

        return $targets;
    }
}
