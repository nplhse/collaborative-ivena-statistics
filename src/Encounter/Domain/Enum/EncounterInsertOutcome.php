<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * Result of inserting one visit (ADR 028): a new visit is inserted and belongs to the inserting import;
 * a visit with the same fingerprint in the same hospital and key generation is skipped as duplicate.
 */
enum EncounterInsertOutcome: string
{
    case Inserted = 'inserted';
    case Duplicate = 'duplicate';
}
