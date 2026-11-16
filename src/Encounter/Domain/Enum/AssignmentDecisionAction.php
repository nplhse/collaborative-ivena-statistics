<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * Manual decision on an assignment conflict (ADR 037, #1307).
 */
enum AssignmentDecisionAction: string
{
    /** The record was attached to one of the candidates. */
    case Assigned = 'assigned';
    /** The conflict was closed without assignment ("leave unassigned for now"). */
    case Deferred = 'deferred';
}
