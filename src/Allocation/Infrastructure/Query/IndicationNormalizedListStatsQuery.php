<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Per-page figures for the normalized indication list. Each method only reads the ids of
 * the current page, so the list stays fast on a large allocation table.
 */
final readonly class IndicationNormalizedListStatsQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * How often each indication occurs as primary or secondary indication of an allocation.
     *
     * @param list<int> $ids
     *
     * @return array<int, array{occurrence_count: int, primary_count: int, secondary_count: int}>
     */
    public function fetchOccurrenceCounts(array $ids): array
    {
        $result = [];
        foreach ($this->rows(
            <<<'SQL'
SELECT n.id,
       (SELECT COUNT(*)::int FROM allocation a WHERE a.indication_normalized_id = n.id) AS primary_count,
       (SELECT COUNT(*)::int FROM allocation a WHERE a.secondary_indication_normalized_id = n.id) AS secondary_count
FROM indication_normalized n
WHERE n.id IN (:ids)
SQL,
            $ids,
        ) as $row) {
            $primary = (int) $row['primary_count'];
            $secondary = (int) $row['secondary_count'];
            $result[(int) $row['id']] = [
                'occurrence_count' => $primary + $secondary,
                'primary_count' => $primary,
                'secondary_count' => $secondary,
            ];
        }

        return $result;
    }

    /**
     * Number of raw indications that target each normalized indication.
     *
     * @param list<int> $ids
     *
     * @return array<int, int>
     */
    public function fetchRawCounts(array $ids): array
    {
        $result = [];
        foreach ($this->rows(
            'SELECT r.target_id AS id, COUNT(*)::int AS raw_count FROM indication_raw r WHERE r.target_id IN (:ids) GROUP BY r.target_id',
            $ids,
        ) as $row) {
            $result[(int) $row['id']] = (int) $row['raw_count'];
        }

        return $result;
    }

    /**
     * Names of the groups each indication belongs to, sorted by group order and name.
     *
     * @param list<int> $ids
     *
     * @return array<int, list<string>>
     */
    public function fetchGroupNames(array $ids): array
    {
        $result = [];
        foreach ($this->rows(
            <<<'SQL'
SELECT gi.indication_normalized_id AS id, g.name
FROM indication_group_indication_normalized gi
JOIN indication_group g ON g.id = gi.indication_group_id
WHERE gi.indication_normalized_id IN (:ids)
ORDER BY g.sort_order NULLS LAST, g.name
SQL,
            $ids,
        ) as $row) {
            $result[(int) $row['id']][] = (string) $row['name'];
        }

        return $result;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->connection->executeQuery(
            $sql,
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        )->fetchAllAssociative();
    }
}
