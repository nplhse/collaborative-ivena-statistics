<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Assignment;

use App\Encounter\Contract\Assignment\EncounterAllocationAssignmentsInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

/**
 * Writes `encounter.allocation_id` (#1300). Changed encounters are cleared first, then holders of the target
 * allocations, then the targets are set, so the unique index holds at every statement (also for a swap).
 *
 * @psalm-suppress UnusedClass Wired as EncounterAllocationAssignmentsInterface in config/services.yaml.
 */
final readonly class EncounterAllocationAssignments implements EncounterAllocationAssignmentsInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function apply(array $allocationByEncounter, ?int $userId): void
    {
        if ([] === $allocationByEncounter) {
            return;
        }

        /** @var array<int|string, int|string|null> $current */
        $current = $this->connection->fetchAllKeyValue(
            'SELECT id, allocation_id FROM encounter WHERE id IN (?)',
            [array_keys($allocationByEncounter)],
            [ArrayParameterType::INTEGER],
        );

        $changed = [];
        foreach ($allocationByEncounter as $encounterId => $allocationId) {
            if (!\array_key_exists($encounterId, $current)) {
                continue; // encounter gone
            }
            $was = null === $current[$encounterId] ? null : (int) $current[$encounterId];
            if ($was !== $allocationId) {
                $changed[$encounterId] = $allocationId;
            }
        }
        if ([] === $changed) {
            return;
        }

        $this->clear(array_keys($changed));
        $targets = array_values(array_filter($changed, static fn (?int $allocationId): bool => null !== $allocationId));
        if ([] !== $targets) {
            $holders = array_map(intval(...), $this->connection->fetchFirstColumn('SELECT id FROM encounter WHERE allocation_id IN (?)', [$targets], [ArrayParameterType::INTEGER]));
            $this->clear($holders);
        }

        $now = $this->clock->now();
        foreach ($changed as $encounterId => $allocationId) {
            if (null === $allocationId) {
                continue;
            }
            $this->connection->executeStatement(
                'UPDATE encounter SET allocation_id = ?, allocation_assigned_at = ?, allocation_assigned_by_id = ? WHERE id = ?',
                [$allocationId, $now, $userId, $encounterId],
                [Types::INTEGER, Types::DATETIME_IMMUTABLE, Types::INTEGER, Types::INTEGER],
            );
        }
    }

    #[\Override]
    public function releaseAllocationsOfImport(int $importId): void
    {
        $this->connection->executeStatement(
            'UPDATE encounter SET allocation_id = NULL, allocation_assigned_at = NULL, allocation_assigned_by_id = NULL
             WHERE allocation_id IN (SELECT id FROM allocation WHERE import_id = ?)',
            [$importId],
        );
    }

    #[\Override]
    public function encounterOfAllocation(int $allocationId): ?int
    {
        $id = $this->connection->fetchOne('SELECT id FROM encounter WHERE allocation_id = ?', [$allocationId]);

        return false === $id ? null : (int) $id;
    }

    /**
     * @param list<int> $encounterIds
     */
    private function clear(array $encounterIds): void
    {
        if ([] === $encounterIds) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE encounter SET allocation_id = NULL, allocation_assigned_at = NULL, allocation_assigned_by_id = NULL WHERE id IN (?) AND allocation_id IS NOT NULL',
            [$encounterIds],
            [ArrayParameterType::INTEGER],
        );
    }
}
