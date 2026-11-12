<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contracts;

use App\Allocation\Domain\Entity\Hospital;
use App\User\Domain\Entity\User;

interface MyHospitalsReaderInterface
{
    /**
     * Hospitals the user owns or holds an access grant for, sorted by name, with the grant mask (null without
     * grant). No admin bypass: administrators only get the hospitals they own or were granted.
     *
     * @return list<array{hospital: Hospital, owned: bool, grantPermissions: int|null}>
     */
    public function findOwnedOrGrantedForUser(User $user): array;
}
