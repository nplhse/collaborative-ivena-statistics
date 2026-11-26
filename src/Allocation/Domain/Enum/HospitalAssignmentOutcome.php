<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

/**
 * What an approved hospital assignment request gave the requester (#975).
 */
enum HospitalAssignmentOutcome: string
{
    case AccessGrant = 'access_grant';
    case Ownership = 'ownership';
}
