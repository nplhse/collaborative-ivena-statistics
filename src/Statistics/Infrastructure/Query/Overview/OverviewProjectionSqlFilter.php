<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

/**
 * Builds SQL WHERE fragments and DBAL parameters for allocation_stats_projection reads.
 */
final class OverviewProjectionSqlFilter
{
    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function buildWhereClause(OverviewQueryCriteria $criteria, string $tableAlias = ''): array
    {
        $prefix = '' === $tableAlias ? '' : $tableAlias.'.';
        $conditions = ['1 = 1'];
        $params = [];

        if ($criteria->from instanceof \DateTimeImmutable) {
            $conditions[] = sprintf('%screated_at >= :from', $prefix);
            $params['from'] = $criteria->from->format('Y-m-d H:i:s');
        }

        if ($criteria->toExclusive instanceof \DateTimeImmutable) {
            $conditions[] = sprintf('%screated_at < :to_exclusive', $prefix);
            $params['to_exclusive'] = $criteria->toExclusive->format('Y-m-d H:i:s');
        }

        if (\is_array($criteria->hospitalIds)) {
            $ids = array_map(static fn (int $id): int => $id, $criteria->hospitalIds);
            $conditions[] = sprintf('%shospital_id IN (%s)', $prefix, implode(',', $ids));
        }

        return [implode(' AND ', $conditions), $params];
    }
}
