<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\IndicationDashboard;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;

/**
 * Builds SQL WHERE fragments for indication dashboard reads on allocation_stats_projection.
 */
final class IndicationDashboardSqlFilter
{
    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function buildScopePeriodWhere(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        string $tableAlias = '',
    ): array {
        $prefix = '' === $tableAlias ? '' : $tableAlias.'.';
        $conditions = ['1 = 1'];
        $params = [];

        if ($from instanceof \DateTimeImmutable) {
            $conditions[] = sprintf('%screated_at >= :from', $prefix);
            $params['from'] = $from->format('Y-m-d H:i:s');
        }

        if ($toExclusive instanceof \DateTimeImmutable) {
            $conditions[] = sprintf('%screated_at < :to_exclusive', $prefix);
            $params['to_exclusive'] = $toExclusive->format('Y-m-d H:i:s');
        }

        if (\is_array($scope->hospitalIds)) {
            $ids = array_map(static fn (int $id): int => $id, $scope->hospitalIds);
            $conditions[] = [] === $ids ? '1 = 0' : sprintf('%shospital_id IN (%s)', $prefix, implode(',', $ids));
        }

        if (null !== $scope->locationCodes) {
            $conditions[] = sprintf('%shospital_location_code IN (%s)', $prefix, implode(',', array_map(intval(...), $scope->locationCodes)));
        }

        if (null !== $scope->tierCodes) {
            $conditions[] = sprintf('%shospital_tier_code IN (%s)', $prefix, implode(',', array_map(intval(...), $scope->tierCodes)));
        }

        return [implode(' AND ', $conditions), $params];
    }
}
