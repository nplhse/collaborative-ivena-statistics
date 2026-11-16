<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter;

use Doctrine\DBAL\Connection;

/**
 * Test fixtures that persist effective EncounterMatch rows directly (without the matcher or the match decider)
 * call this afterwards, so that `encounter.allocation_id` (#1300) says the same as the ED matching history,
 * as the production write paths guarantee.
 */
final class EffectiveAssignments
{
    public static function syncFromMatches(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
UPDATE encounter e
SET allocation_id = NULL, allocation_assigned_at = NULL, allocation_assigned_by_id = NULL
WHERE e.allocation_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM encounter_match m WHERE m.encounter_id = e.id AND m.allocation_id = e.allocation_id AND m.status IN ('auto_accepted', 'confirmed'))
SQL);
        $connection->executeStatement(<<<'SQL'
UPDATE encounter e
SET allocation_id = m.allocation_id, allocation_assigned_at = COALESCE(m.decided_at, m.created_at), allocation_assigned_by_id = m.decided_by_id
FROM encounter_match m
WHERE m.encounter_id = e.id AND m.status IN ('auto_accepted', 'confirmed') AND e.allocation_id IS DISTINCT FROM m.allocation_id
SQL);
    }
}
