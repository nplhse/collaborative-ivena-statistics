<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Assignment;

/**
 * Result of finding the encounter of a record (ADR 037, "Finding the encounter").
 */
enum AssignmentKind: string
{
    /** Exactly one candidate; the record is attached to it. */
    case Assigned = 'assigned';

    /** No candidate yet; the record is kept unassigned and tried again later (#1307). */
    case NoCandidate = 'no_candidate';

    /** The case pseudonym matches several encounters and nothing decides between them. */
    case Ambiguous = 'ambiguous';

    /** The allocation link and the case pseudonym point to different encounters. */
    case Contradictory = 'contradictory';
}
