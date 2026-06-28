<?php

declare(strict_types=1);

namespace App\Allocation\Application\Export;

use App\Allocation\Application\Service\HospitalPermissionAccess;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;

final readonly class ExportAccessService
{
    public function __construct(
        private HospitalPermissionAccess $hospitalPermissionAccess,
    ) {
    }

    public function canExport(User $user): bool
    {
        if (!\in_array(UserRole::PARTICIPANT, $user->getRoles(), true)
            && !\in_array(UserRole::ADMIN, $user->getRoles(), true)) {
            return false;
        }

        return [] !== $this->resolveExportHospitalIds($user);
    }

    /**
     * @return list<int>
     */
    public function resolveExportHospitalIds(User $user): array
    {
        return $this->hospitalPermissionAccess->resolveHospitalIdsWithPermission($user, HospitalPermission::Export);
    }

    /**
     * @param list<int>|null $requestedHospitalIds
     *
     * @return list<int>
     */
    public function resolveEffectiveHospitalIds(User $user, ?array $requestedHospitalIds): array
    {
        $allowed = $this->resolveExportHospitalIds($user);
        if ([] === $allowed) {
            return [];
        }

        if (null === $requestedHospitalIds || [] === $requestedHospitalIds) {
            return $allowed;
        }

        $requested = array_values(array_unique(array_map(intval(...), $requestedHospitalIds)));
        $effective = array_values(array_intersect($requested, $allowed));

        return [] !== $effective ? $effective : $allowed;
    }
}
