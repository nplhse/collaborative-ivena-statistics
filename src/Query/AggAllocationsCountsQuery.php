<?php

declare(strict_types=1);

namespace App\Query;

use Doctrine\DBAL\Connection;

final readonly class AggAllocationsCountsQuery
{
    public function __construct(
        private Connection $db,
    ) {
    }

    /**
     * @return array<string,mixed>|false
     */
    public function fetchOne(string $scopeType, string $scopeId, string $granularity, string $periodKey): array|false
    {
        return $this->db->fetchAssociative(
            'SELECT *
               FROM agg_allocations_counts
              WHERE scope_type = :t
                AND scope_id = :i
                AND period_gran = :g
                AND period_key = :k',
            [
                't' => $scopeType,
                'i' => $scopeId,
                'g' => $granularity,
                'k' => $periodKey,
            ]
        );
    }

    /**
     * @param string[] $periodKeys
     *
     * @return array<string, array<string, mixed>|null>
     */
    public function fetchMany(string $scopeType, string $scopeId, string $granularity, array $periodKeys): array
    {
        if ([] === $periodKeys) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($periodKeys), '?'));
        $params = array_merge([$scopeType, $scopeId, $granularity], $periodKeys);

        $sql = sprintf(
            'SELECT *,
                computed_at
           FROM agg_allocations_counts
          WHERE scope_type  = ?
            AND scope_id    = ?
            AND period_gran = ?
            AND period_key  IN (%s)',
            $placeholders
        );

        $rows = $this->db->fetchAllAssociative($sql, $params);

        // prefill with NULL, not false
        $map = array_fill_keys($periodKeys, null);

        foreach ($rows as $row) {
            $pk = (string) $row['period_key'];
            $map[$pk] = $row; // array
        }

        return $map;
    }
}
