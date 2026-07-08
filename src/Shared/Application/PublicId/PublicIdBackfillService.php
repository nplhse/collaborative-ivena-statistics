<?php

declare(strict_types=1);

namespace App\Shared\Application\PublicId;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class PublicIdBackfillService
{
    /** @var list<string> */
    public const array TABLE_ORDER = [
        'hospital',
        'secondary_transport',
        'indication_raw',
        'mci_case',
        'allocation',
    ];

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<string>|null $tables
     */
    public function run(
        bool $dryRun = false,
        ?array $tables = null,
        int $batchSize = 5000,
        int $maxRuntimeSeconds = 0,
        ?PublicIdBackfillRunControl $runControl = null,
    ): PublicIdBackfillResult {
        $selectedTables = $this->resolveTables($tables);
        $updatedByTable = [];
        $remainingByTable = [];
        $startedAt = microtime(true);

        foreach ($selectedTables as $table) {
            if ($dryRun) {
                $remaining = $this->countRemaining($table);
                $remainingByTable[$table] = $remaining;
                $updatedByTable[$table] = 0;

                continue;
            }

            $updatedByTable[$table] = $this->backfillTable(
                $table,
                $batchSize,
                $maxRuntimeSeconds,
                $startedAt,
                $runControl,
            );
            $remainingByTable[$table] = $this->countRemaining($table);

            if (
                $maxRuntimeSeconds > 0
                && (microtime(true) - $startedAt) >= $maxRuntimeSeconds
                && $remainingByTable[$table] > 0
            ) {
                foreach (self::TABLE_ORDER as $remainingTable) {
                    if (!\array_key_exists($remainingTable, $remainingByTable)) {
                        $remainingByTable[$remainingTable] = $this->countRemaining($remainingTable);
                        $updatedByTable[$remainingTable] ??= 0;
                    }
                }

                break;
            }
        }

        $completed = !$dryRun && [] === array_filter($remainingByTable);

        return new PublicIdBackfillResult(
            $updatedByTable,
            $remainingByTable,
            $completed,
        );
    }

    /**
     * @param list<string>|null $tables
     *
     * @return list<string>
     */
    private function resolveTables(?array $tables): array
    {
        if (null === $tables || [] === $tables || ['all'] === $tables) {
            return self::TABLE_ORDER;
        }

        $invalid = array_diff($tables, self::TABLE_ORDER);
        if ([] !== $invalid) {
            throw new \InvalidArgumentException(sprintf('Unknown table(s): %s. Allowed: %s', implode(', ', $invalid), implode(', ', self::TABLE_ORDER)));
        }

        return array_values(array_intersect(self::TABLE_ORDER, $tables));
    }

    private function backfillTable(
        string $table,
        int $batchSize,
        int $maxRuntimeSeconds,
        float $startedAt,
        ?PublicIdBackfillRunControl $runControl,
    ): int {
        $updated = 0;
        $lastId = 0;

        while (true) {
            $runControl?->throwIfStopRequested();

            if ($maxRuntimeSeconds > 0 && (microtime(true) - $startedAt) >= $maxRuntimeSeconds) {
                break;
            }

            $ids = $this->fetchNextIds($table, $lastId, $batchSize);
            if ([] === $ids) {
                break;
            }

            $updated += $this->assignPublicIds($table, $ids);
            $lastId = $ids[array_key_last($ids)];
        }

        return $updated;
    }

    /**
     * @return list<int>
     */
    private function fetchNextIds(string $table, int $lastId, int $batchSize): array
    {
        /** @var list<int|string> $rows */
        $rows = $this->connection->fetchFirstColumn(
            sprintf(
                'SELECT id FROM %s WHERE public_id IS NULL AND id > :lastId ORDER BY id ASC LIMIT :batchSize',
                $table,
            ),
            [
                'lastId' => $lastId,
                'batchSize' => $batchSize,
            ],
            [
                'lastId' => ParameterType::INTEGER,
                'batchSize' => ParameterType::INTEGER,
            ],
        );

        return array_map(static fn (int|string $id): int => (int) $id, $rows);
    }

    /**
     * @param list<int> $ids
     */
    private function assignPublicIds(string $table, array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        $values = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $values[] = sprintf('(:id%d, :pid%d)', $index, $index);
            $params[sprintf('id%d', $index)] = $id;
            $params[sprintf('pid%d', $index)] = Uuid::v4()->toRfc4122();
        }

        return $this->connection->executeStatement(
            sprintf(
                'UPDATE %s AS t SET public_id = v.pid FROM (VALUES %s) AS v(id, pid) WHERE t.id = v.id::int',
                $table,
                implode(', ', $values),
            ),
            $params,
        );
    }

    private function countRemaining(string $table): int
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE public_id IS NULL', $table),
        );
    }
}
