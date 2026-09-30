<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;

final class ClosureIntervalSqlFilter
{
    /**
     * @return array{string, array<string, mixed>, array<string, mixed>}
     */
    public static function build(
        StatisticsPeriodBounds $period,
        StatisticsScopeCriteria $scope,
        string $alias = 'ci',
    ): array {
        [$scopeWhere, $params, $types] = self::buildScope($scope, $alias);
        $where = 'TRUE' === $scopeWhere ? [] : [$scopeWhere];

        if ($period->from instanceof \DateTimeImmutable) {
            $where[] = sprintf('%s.ends_at > :period_from', $alias);
            $params['period_from'] = $period->from;
            $types['period_from'] = Types::DATETIME_IMMUTABLE;
        }

        if ($period->toExclusive instanceof \DateTimeImmutable) {
            $where[] = sprintf('%s.starts_at < :period_to', $alias);
            $params['period_to'] = $period->toExclusive;
            $types['period_to'] = Types::DATETIME_IMMUTABLE;
        }

        return [[] === $where ? 'TRUE' : implode(' AND ', $where), $params, $types];
    }

    /**
     * @return array{string, array<string, mixed>, array<string, mixed>}
     */
    public static function buildScope(StatisticsScopeCriteria $scope, string $alias = 'ci'): array
    {
        $where = [];
        $params = [];
        $types = [];

        if (\is_array($scope->hospitalIds)) {
            if ([] === $scope->hospitalIds) {
                $where[] = 'FALSE';
            } else {
                $where[] = sprintf('%s.hospital_id IN (:hospital_ids)', $alias);
                $params['hospital_ids'] = $scope->hospitalIds;
                $types['hospital_ids'] = ArrayParameterType::INTEGER;
            }
        }

        return [[] === $where ? 'TRUE' : implode(' AND ', $where), $params, $types];
    }

    public static function clippedStart(StatisticsPeriodBounds $period, string $alias = 'ci'): string
    {
        return $period->from instanceof \DateTimeImmutable
            ? sprintf('GREATEST(%s.starts_at, :period_from)', $alias)
            : sprintf('%s.starts_at', $alias);
    }

    public static function clippedEnd(StatisticsPeriodBounds $period, string $alias = 'ci'): string
    {
        return $period->toExclusive instanceof \DateTimeImmutable
            ? sprintf('LEAST(%s.ends_at, :period_to)', $alias)
            : sprintf('%s.ends_at', $alias);
    }
}
