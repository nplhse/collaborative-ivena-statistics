<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Adapter;

use App\Allocation\Application\Service\HospitalPermissionAccess;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(HospitalAccessInterface::class)]
final readonly class DoctrineHospitalAccess implements HospitalAccessInterface
{
    public function __construct(
        private HospitalRepository $hospitalRepository,
        private HospitalPermissionAccess $hospitalPermissionAccess,
    ) {
    }

    #[\Override]
    public function isAdminHospitalScopeUser(User $user): bool
    {
        return \in_array(UserRole::ADMIN, $user->getRoles(), true);
    }

    #[\Override]
    public function canUseMyHospitalsScope(User $user): bool
    {
        if ($this->isAdminHospitalScopeUser($user)) {
            return true;
        }

        if (!\in_array(UserRole::PARTICIPANT, $user->getRoles(), true)) {
            return false;
        }

        return $this->countAccessibleHospitals($user) > 0;
    }

    #[\Override]
    public function canUseBenchmarkingScope(User $user): bool
    {
        if ($this->isAdminHospitalScopeUser($user)) {
            return true;
        }

        if (!\in_array(UserRole::PARTICIPANT, $user->getRoles(), true)) {
            return false;
        }

        return \count($this->benchmarkingHospitalIds($user)) > 0;
    }

    #[\Override]
    public function canSelectHospitalScope(User $user, int $hospitalId): bool
    {
        return \in_array($hospitalId, $this->accessibleHospitalIds($user), true);
    }

    #[\Override]
    public function countAccessibleHospitals(User $user): int
    {
        if ($this->isAdminHospitalScopeUser($user)) {
            return $this->hospitalRepository->countAccessibleHospitals($user);
        }

        return \count($this->accessibleHospitalIds($user));
    }

    #[\Override]
    public function accessibleHospitalIds(User $user): array
    {
        return $this->hospitalPermissionAccess->resolveHospitalIdsWithPermission($user, HospitalPermission::Statistics);
    }

    #[\Override]
    public function benchmarkingHospitalIds(User $user): array
    {
        return $this->hospitalPermissionAccess->resolveHospitalIdsWithPermission($user, HospitalPermission::Benchmarking);
    }
}
