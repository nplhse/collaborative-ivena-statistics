<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

/**
 * Lifecycle of a hospital assignment request (#975). Only a pending request can be withdrawn or decided.
 */
enum HospitalAssignmentRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
}
