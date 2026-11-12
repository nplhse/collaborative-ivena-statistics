<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Allocation\Domain\Entity\Hospital;
use App\User\Domain\Entity\User;

/**
 * Who has access to a hospital in the sense of ADR 027: the owner or a user with a hospital access
 * grant. ROLE_ADMIN alone does not count.
 */
interface HospitalMembershipReaderInterface
{
    public function hasHospitalAccess(User $user, Hospital $hospital): bool;

    /**
     * Owner and users with a hospital access grant, ordered by username.
     *
     * @return list<User>
     */
    public function findMembers(Hospital $hospital): array;
}
