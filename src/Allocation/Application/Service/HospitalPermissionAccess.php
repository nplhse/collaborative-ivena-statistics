<?php

declare(strict_types=1);

namespace App\Allocation\Application\Service;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Repository\HospitalAccessGrantRepository;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;

final readonly class HospitalPermissionAccess
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private HospitalRepository $hospitalRepository,
        private HospitalAccessGrantRepository $hospitalAccessGrantRepository,
    ) {
    }

    public function hasPermission(User $user, int $hospitalId, HospitalPermission $permission): bool
    {
        if (\in_array(UserRole::ADMIN, $user->getRoles(), true)) {
            return true;
        }

        $hospital = $this->hospitalRepository->findById($hospitalId);
        if (!$hospital instanceof Hospital) {
            return false;
        }

        if ($this->isOwner($user, $hospital)) {
            return true;
        }

        $grant = $this->hospitalAccessGrantRepository->findForUserAndHospital($user, $hospital);
        if (!$grant instanceof \App\Allocation\Domain\Entity\HospitalAccessGrant) {
            return false;
        }

        return HospitalPermissionMask::has($grant->getPermissions(), $permission);
    }

    /**
     * @return list<int>
     */
    public function resolveHospitalIdsWithPermission(User $user, HospitalPermission $permission): array
    {
        /** @var list<int|string> $rawIds */
        $rawIds = $this->hospitalRepository
            ->getQueryBuilderForHospitalsWithPermission($user, $permission)
            ->select('h.id')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (int|string $id): int => (int) $id, $rawIds);
    }

    public function isOwner(User $user, Hospital $hospital): bool
    {
        $owner = $hospital->getOwner();

        return $owner instanceof User && $owner->getId() === $user->getId();
    }

    public function canEditHospital(User $user, Hospital $hospital): bool
    {
        if (\in_array(UserRole::ADMIN, $user->getRoles(), true)) {
            return true;
        }

        return $this->isOwner($user, $hospital);
    }

    public function canManageAccessGrants(User $user, Hospital $hospital): bool
    {
        return $this->canEditHospital($user, $hospital);
    }
}
