<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * Review state of a record kept without encounter (ADR 037, #1307).
 */
enum PendingAssignmentStatus: string
{
    /** Waiting: an assignment conflict for review, or a record without candidate. */
    case Open = 'open';
    /** A reviewer chose "leave unassigned for now"; the record stays eligible for automatic retries. */
    case Deferred = 'deferred';
}
