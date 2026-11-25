<?php

declare(strict_types=1);

namespace App\Allocation\Application\Allocations;

use App\Allocation\Application\Service\HospitalPermissionAccess;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\User\Domain\Entity\User;

final readonly class AllocationListHospitalScopeResolver
{
    public const string SCOPE_MY_HOSPITALS = 'my_hospitals';

    public function __construct(
        private HospitalPermissionAccess $hospitalPermissionAccess,
    ) {
    }

    public function canUseFilter(User $user): bool
    {
        return [] !== $this->accessibleHospitalIds($user);
    }

    /**
     * @return list<int>|null null = no hospital scope filter
     */
    public function resolveHospitalIdsFromQuery(
        ?User $user,
        ?string $hospitalFilter,
        ?string $hospitalScope,
        ?int $hospitalId,
    ): ?array {
        if (null !== $hospitalFilter && '' !== $hospitalFilter) {
            if (self::SCOPE_MY_HOSPITALS === $hospitalFilter) {
                return $this->resolveHospitalIds($user, self::SCOPE_MY_HOSPITALS, null);
            }

            if (ctype_digit($hospitalFilter)) {
                return $this->resolveHospitalIds($user, self::SCOPE_MY_HOSPITALS, (int) $hospitalFilter);
            }

            return $this->resolveHospitalIds($user, null, null);
        }

        return $this->resolveHospitalIds($user, $hospitalScope, $hospitalId);
    }

    /**
     * Administrators may list all hospitals; everyone else sees only their own (#1566, ADR 044).
     */
    public function canSeeAllHospitals(?User $user): bool
    {
        return $user instanceof User && $this->hospitalPermissionAccess->isAdministrator($user);
    }

    /**
     * Without a scope, administrators get no restriction and everyone else their own hospitals (#1566).
     *
     * @return list<int>|null null = no hospital scope filter
     */
    public function resolveHospitalIds(?User $user, ?string $hospitalScope, ?int $hospitalId): ?array
    {
        if (!$user instanceof User) {
            return [];
        }

        if (self::SCOPE_MY_HOSPITALS !== $hospitalScope) {
            return $this->canSeeAllHospitals($user) ? null : $this->accessibleHospitalIds($user);
        }

        $accessibleIds = $this->accessibleHospitalIds($user);
        if ([] === $accessibleIds) {
            return [];
        }

        if (null === $hospitalId || $hospitalId <= 0) {
            return $accessibleIds;
        }

        return \in_array($hospitalId, $accessibleIds, true) ? [$hospitalId] : [];
    }

    /**
     * @return list<int>
     */
    private function accessibleHospitalIds(User $user): array
    {
        return $this->hospitalPermissionAccess->resolveHospitalIdsWithPermission(
            $user,
            HospitalPermission::View,
        );
    }
}
