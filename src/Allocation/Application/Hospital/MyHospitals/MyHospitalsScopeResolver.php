<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\MyHospitals;

use App\Allocation\Application\Contracts\MyHospitalsReaderInterface;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;

/**
 * Resolves the hospitals and permissions for My Hospitals with a single query.
 *
 * Owners see everything for their hospitals. Grant holders see the hospitals whose grant includes VIEW, with the
 * permissions of their grant. Administrators are not given every hospital here: they get the hospitals they own or
 * were granted, with all permissions on them (as everywhere else for admins).
 */
final readonly class MyHospitalsScopeResolver
{
    public function __construct(
        private MyHospitalsReaderInterface $myHospitalsReader,
    ) {
    }

    public function resolve(User $user): MyHospitalsScope
    {
        $allPermissions = HospitalPermissionMask::fromPermissions(HospitalPermission::assignableCases());
        $isAdmin = \in_array(UserRole::ADMIN, $user->getRoles(), true);

        $entries = [];
        foreach ($this->myHospitalsReader->findOwnedOrGrantedForUser($user) as $row) {
            if ($row['owned'] || $isAdmin) {
                $entries[] = new MyHospitalsEntry($row['hospital'], $row['owned'], $allPermissions);

                continue;
            }

            $mask = $row['grantPermissions'] ?? 0;
            if (!HospitalPermissionMask::has($mask, HospitalPermission::View)) {
                continue;
            }

            $entries[] = new MyHospitalsEntry($row['hospital'], false, $mask);
        }

        return new MyHospitalsScope($entries);
    }
}
