<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

/**
 * Whether the department of an allocation was closed for its urgency at `created_at`,
 * according to the imported closure intervals (#571).
 *
 * This is derived from closure imports and stands next to the IVENA snapshot
 * `Allocation::$departmentWasClosed`; it never replaces it.
 */
enum ClosureAtAssignment: string
{
    /** A closure interval of the hospital and department closes the allocation's SK at `created_at`. */
    case CLOSED = 'closed';

    /** The hospital has closure data for `created_at`, but no interval closes the department for that SK. */
    case OPEN = 'open';

    /** No closure data covers `created_at` for the hospital. */
    case UNKNOWN = 'unknown';

    public function label(): string
    {
        return 'closure_at_assignment.'.$this->value;
    }
}
