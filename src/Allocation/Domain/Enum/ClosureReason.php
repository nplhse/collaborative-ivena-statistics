<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

/**
 * Shared closure reasons. Comparable across hospitals.
 * "k.A." is its own category, not a missing value.
 */
enum ClosureReason: string
{
    case EMERGENCY_DEPARTMENT_OVERLOAD = 'emergency_department_overload';

    case NO_BED_CAPACITY = 'no_bed_capacity';

    case TECHNICAL_FAULT = 'technical_fault';

    case OPERATING_ROOM_NOTICE = 'operating_room_notice';

    case NOT_SPECIFIED = 'not_specified';
}
