<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Builds SQL predicates for dual-scope benchmark reads on allocation_stats_projection.
 */
final class BenchmarkSqlFilter
{
    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ArrayParameterType>}
     */
    public static function buildSidePredicate(
        StatisticsScopeCriteria $scope,
        StatisticsPeriodBounds $period,
        string $prefix,
        string $tableAlias = '',
    ): array {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return ['1 = 0', [], []];
        }

        $columnPrefix = '' === $tableAlias ? '' : $tableAlias.'.';
        $conditions = [];
        $params = [];
        /** @var array<string, ArrayParameterType> $types */
        $types = [];

        if ($period->from instanceof \DateTimeImmutable) {
            $conditions[] = sprintf('%screated_at >= :%s_from', $columnPrefix, $prefix);
            $params[sprintf('%s_from', $prefix)] = $period->from->format('Y-m-d H:i:s');
        }

        if ($period->toExclusive instanceof \DateTimeImmutable) {
            $conditions[] = sprintf('%screated_at < :%s_to_exclusive', $columnPrefix, $prefix);
            $params[sprintf('%s_to_exclusive', $prefix)] = $period->toExclusive->format('Y-m-d H:i:s');
        }

        if (\is_array($scope->hospitalIds)) {
            $ids = array_map(static fn (int $id): int => $id, $scope->hospitalIds);
            $conditions[] = sprintf('%shospital_id IN (:%s_hospital_ids)', $columnPrefix, $prefix);
            $params[sprintf('%s_hospital_ids', $prefix)] = $ids;
            $types[sprintf('%s_hospital_ids', $prefix)] = ArrayParameterType::INTEGER;
        }

        if (null !== $scope->locationCodes) {
            $locationCodes = array_map(intval(...), $scope->locationCodes);
            $conditions[] = sprintf('%shospital_location_code IN (:%s_location_codes)', $columnPrefix, $prefix);
            $params[sprintf('%s_location_codes', $prefix)] = $locationCodes;
            $types[sprintf('%s_location_codes', $prefix)] = ArrayParameterType::INTEGER;
        }

        if (null !== $scope->tierCodes) {
            $tierCodes = array_map(intval(...), $scope->tierCodes);
            $conditions[] = sprintf('%shospital_tier_code IN (:%s_tier_codes)', $columnPrefix, $prefix);
            $params[sprintf('%s_tier_codes', $prefix)] = $tierCodes;
            $types[sprintf('%s_tier_codes', $prefix)] = ArrayParameterType::INTEGER;
        }

        if ([] === $conditions) {
            return ['1 = 1', $params, $types];
        }

        return ['('.implode(' AND ', $conditions).')', $params, $types];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ArrayParameterType>}
     */
    public static function buildUnionWhere(
        StatisticsScopeCriteria $primaryScope,
        StatisticsPeriodBounds $primaryPeriod,
        StatisticsScopeCriteria $comparisonScope,
        StatisticsPeriodBounds $comparisonPeriod,
        string $tableAlias = '',
    ): array {
        [$primarySql, $primaryParams, $primaryTypes] = self::buildSidePredicate(
            $primaryScope,
            $primaryPeriod,
            'primary',
            $tableAlias,
        );
        [$comparisonSql, $comparisonParams, $comparisonTypes] = self::buildSidePredicate(
            $comparisonScope,
            $comparisonPeriod,
            'comparison',
            $tableAlias,
        );

        return [
            sprintf('(%s OR %s)', $primarySql, $comparisonSql),
            array_merge($primaryParams, $comparisonParams),
            array_merge($primaryTypes, $comparisonTypes),
        ];
    }
}
