<?php

declare(strict_types=1);

namespace App\Encounter\Application\Assignment;

/**
 * Result of a manual decision on an assignment conflict (#1307). Only `Decided` changed the record; every
 * other outcome left the decision out and the page shows the current state.
 */
enum AssignmentDecisionOutcome: string
{
    case Decided = 'decided';
    /** The pending record is gone or no longer an open conflict. */
    case NotOpen = 'not_open';
    /** The kind or the candidates differ from what the reviewer saw; the record now shows the fresh state. */
    case Changed = 'changed';
    /** The chosen encounter is not one of the conflict's candidates. */
    case InvalidCandidate = 'invalid_candidate';
    /** The schema's record is gone or already attached, or the schema is no longer registered. */
    case RecordChanged = 'record_changed';
    /** The chosen encounter was deleted meanwhile. */
    case EncounterGone = 'encounter_gone';
}
