<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\MyHospitals;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;

/**
 * One hospital on the My Hospitals page with the permissions the user holds for it
 * (bitmask, see {@see HospitalPermissionMask}).
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the My Hospitals template.
 */
final readonly class MyHospitalsEntry
{
    public function __construct(
        public Hospital $hospital,
        public bool $owned,
        public int $permissionMask,
    ) {
    }

    public function has(HospitalPermission $permission): bool
    {
        return HospitalPermissionMask::has($this->permissionMask, $permission);
    }
}
