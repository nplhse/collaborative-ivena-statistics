<?php

declare(strict_types=1);

namespace App\Onboarding\Domain\Enum;

/**
 * Where a participant stands on the way to their clinic (#1483): access through ownership or a grant, or else the
 * state of their current hospital assignment request.
 */
enum ClinicAccessStatus: string
{
    case Granted = 'granted';
    case NoRequest = 'no_request';
    case Pending = 'pending';
    case Rejected = 'rejected';
}
