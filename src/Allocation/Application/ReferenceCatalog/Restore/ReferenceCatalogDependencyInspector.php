<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Restore;

use Doctrine\DBAL\Connection;

/**
 * Finds every row outside the catalog that refers to catalog rows (ADR 020, inventory of #740).
 *
 * Any such row blocks a restore: with a foreign key it would stop the delete or, for cascading keys,
 * vanish silently; without one it would keep a local id that no longer exists.
 */
final readonly class ReferenceCatalogDependencyInspector
{
    /**
     * Table → [kind, condition]. The condition limits the count to rows that refer to the catalog.
     */
    private const array DEPENDENTS = [
        'allocation' => ['operational data', null],
        'mci_case' => ['operational data', null],
        'import' => ['operational data', null],
        'closure_interval' => ['operational data', null],
        'monthly_reminder_dispatch' => ['operational data', null],
        'hospital_access_grant' => ['permissions', null],
        'hospital' => ['permissions (hospital owner)', 'owner_id IS NOT NULL'],
        'allocation_stats_projection' => ['projection', null],
        'closure_event' => ['projection', null],
        'closure_analysis_interval' => ['projection', null],
        'closure_volume_hour' => ['projection', null],
        'closure_rebuild_request' => ['projection', null],
        'closure_relation_candidate' => ['projection', null],
        'kpi_daily' => ['projection', null],
        'job_run' => ['monitoring', 'hospital_id IS NOT NULL'],
        'saved_explorer_view' => ['user data (saved views with local ids)', 'is_system = false'],
        'notification' => ['user data (hospital notifications)', 'scope_reference_id IS NOT NULL'],
    ];

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<RestoreBlocker>
     */
    public function blockers(): array
    {
        // Some projection tables only exist after their migration; a missing table holds no rows.
        /** @var list<string> $existing */
        $existing = $this->connection->fetchFirstColumn('SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()');
        $tables = array_flip($existing);

        $blockers = [];
        foreach (self::DEPENDENTS as $table => [$kind, $condition]) {
            if (!isset($tables[$table])) {
                continue;
            }

            $rows = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s%s', $table, null === $condition ? '' : ' WHERE '.$condition));
            if ($rows > 0) {
                $blockers[] = new RestoreBlocker($table, $kind, $rows);
            }
        }

        return $blockers;
    }
}
