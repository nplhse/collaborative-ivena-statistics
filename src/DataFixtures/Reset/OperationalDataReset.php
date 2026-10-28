<?php

declare(strict_types=1);

namespace App\DataFixtures\Reset;

use App\Statistics\Application\Contract\MaterializedViewRefresherInterface;
use Doctrine\DBAL\Connection;

/**
 * Removes development data that depends on the reference catalog (ADR 020, development reset).
 *
 * Afterwards nothing blocks a restore: operational data, projections, access grants,
 * hospital owners, hospital job runs and notifications and saved user views are gone.
 * Users and the reference catalog stay; users have no hospitals assigned any more.
 */
final readonly class OperationalDataReset
{
    /**
     * In delete order: rows that refer to others go first.
     *
     * @var list<array{table: string, kind: string, condition: ?string}>
     */
    private const array STEPS = [
        ['table' => 'allocation_stats_projection', 'kind' => 'projection', 'condition' => null],
        ['table' => 'closure_volume_hour', 'kind' => 'projection', 'condition' => null],
        ['table' => 'closure_rebuild_request', 'kind' => 'projection', 'condition' => null],
        ['table' => 'closure_relation_candidate', 'kind' => 'projection', 'condition' => null],
        ['table' => 'closure_analysis_source', 'kind' => 'projection', 'condition' => null],
        ['table' => 'closure_analysis_care_level', 'kind' => 'projection', 'condition' => null],
        ['table' => 'closure_analysis_interval', 'kind' => 'projection', 'condition' => null],
        ['table' => 'closure_event', 'kind' => 'projection', 'condition' => null],
        ['table' => 'kpi_daily', 'kind' => 'projection', 'condition' => null],
        ['table' => 'generation_run', 'kind' => 'operational data', 'condition' => null],
        ['table' => 'monthly_reminder_dispatch', 'kind' => 'operational data', 'condition' => null],
        ['table' => 'allocation', 'kind' => 'operational data', 'condition' => null],
        ['table' => 'mci_case', 'kind' => 'operational data', 'condition' => null],
        ['table' => 'closure_interval', 'kind' => 'operational data', 'condition' => null],
        ['table' => 'import_reject', 'kind' => 'operational data', 'condition' => null],
        ['table' => 'import_run', 'kind' => 'operational data', 'condition' => null],
        ['table' => 'import', 'kind' => 'operational data', 'condition' => null],
        ['table' => 'hospital_access_grant', 'kind' => 'permissions', 'condition' => null],
        ['table' => 'job_run', 'kind' => 'monitoring', 'condition' => 'hospital_id IS NOT NULL'],
        ['table' => 'notification', 'kind' => 'user data', 'condition' => 'scope_reference_id IS NOT NULL'],
        ['table' => 'saved_explorer_view', 'kind' => 'user data', 'condition' => 'is_system = false'],
    ];

    public function __construct(
        private Connection $connection,
        private MaterializedViewRefresherInterface $materializedViews,
    ) {
    }

    /**
     * What the reset would change, without changing anything.
     *
     * @return list<ResetStep>
     */
    public function preview(): array
    {
        $steps = [];
        foreach ($this->existingSteps() as $step) {
            $steps[] = new ResetStep($step['table'], $step['kind'], 'delete', $this->count($step['table'], $step['condition']));
        }
        $steps[] = new ResetStep('hospital', 'permissions', 'clear owner', $this->count('hospital', 'owner_id IS NOT NULL'));

        return $steps;
    }

    /**
     * Runs the reset in one transaction, then refreshes the materialized views.
     *
     * @return list<ResetStep> what was changed
     */
    public function run(): array
    {
        $steps = $this->connection->transactional(function (): array {
            $steps = [];
            foreach ($this->existingSteps() as $step) {
                $rows = $this->connection->executeStatement(sprintf('DELETE FROM %s%s', $step['table'], $this->where($step['condition'])));
                $steps[] = new ResetStep($step['table'], $step['kind'], 'delete', $rows);
            }
            $owners = $this->connection->executeStatement('UPDATE hospital SET owner_id = NULL WHERE owner_id IS NOT NULL');
            $steps[] = new ResetStep('hospital', 'permissions', 'clear owner', $owners);

            return $steps;
        });

        $this->materializedViews->refresh();

        return $steps;
    }

    /**
     * Some tables only exist after their migration; a missing table has nothing to reset.
     *
     * @return list<array{table: string, kind: string, condition: ?string}>
     */
    private function existingSteps(): array
    {
        /** @var list<string> $existing */
        $existing = $this->connection->fetchFirstColumn('SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()');
        $tables = array_flip($existing);

        return array_values(array_filter(self::STEPS, static fn (array $step): bool => isset($tables[$step['table']])));
    }

    private function count(string $table, ?string $condition): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s%s', $table, $this->where($condition)));
    }

    private function where(?string $condition): string
    {
        return null === $condition ? '' : ' WHERE '.$condition;
    }
}
