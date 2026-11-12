<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\MyHospitals;

use App\Allocation\Domain\Entity\Hospital;

/**
 * One hospital on the My Hospitals page with the permissions the user holds for it
 * (bitmask, see {@see \App\Allocation\Domain\HospitalPermissionMask}).
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the My Hospitals template; owned and permissionMask decide which
 *                 data-status parts the overview shows (#971).
 */
final readonly class MyHospitalsEntry
{
    public function __construct(
        public Hospital $hospital,
        public bool $owned,
        public int $permissionMask,
    ) {
    }
}
