<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

use App\User\Domain\Entity\User;

interface HospitalAccessInterface
{
    public function countAccessibleHospitals(User $user): int;

    /**
     * @return list<int>
     */
    public function accessibleHospitalIds(User $user): array;
}
