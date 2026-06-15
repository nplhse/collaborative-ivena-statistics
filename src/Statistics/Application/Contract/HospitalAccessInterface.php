<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

use App\User\Domain\Entity\User;

interface HospitalAccessInterface
{
    public function isAdminHospitalScopeUser(User $user): bool;

    public function canUseMyHospitalsScope(User $user): bool;

    public function canUseBenchmarkingScope(User $user): bool;

    public function canSelectHospitalScope(User $user, int $hospitalId): bool;

    public function countAccessibleHospitals(User $user): int;

    /**
     * @return list<int>
     */
    public function accessibleHospitalIds(User $user): array;

    /**
     * @return list<int>
     */
    public function benchmarkingHospitalIds(User $user): array;
}
