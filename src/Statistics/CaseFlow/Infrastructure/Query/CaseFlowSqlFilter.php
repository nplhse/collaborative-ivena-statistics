<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Infrastructure\Query\ProjectionDrawerFilterSql;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Builds SQL WHERE fragments for case flow reads on allocation_stats_projection.
 */
final class CaseFlowSqlFilter
{
    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ArrayParameterType>}
     */
    public static function buildScopePeriodWhere(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        string $tableAlias = 'asp',
        ?int $originStateId = null,
        ?StatisticsDrawerFilter $drawerFilter = null,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch = CaseFlowDispatchAreaMatch::Related,
    ): array {
        $prefix = '' === $tableAlias ? '' : $tableAlias.'.';
        $conditions = ['1 = 1'];
        $params = [];
        /** @var array<string, ArrayParameterType> $types */
        $types = [];

        if ($from instanceof \DateTimeImmutable) {
            $conditions[] = sprintf('%screated_at >= :from', $prefix);
            $params['from'] = $from->format('Y-m-d H:i:s');
        }

        if ($toExclusive instanceof \DateTimeImmutable) {
            $conditions[] = sprintf('%screated_at < :to_exclusive', $prefix);
            $params['to_exclusive'] = $toExclusive->format('Y-m-d H:i:s');
        }

        if (null !== $originStateId) {
            $conditions[] = sprintf('%sstate_id = :origin_state_id', $prefix);
            $params['origin_state_id'] = $originStateId;
        } elseif (\is_array($scope->hospitalIds)) {
            $ids = array_map(static fn (int $id): int => $id, $scope->hospitalIds);
            if ([] === $ids) {
                $conditions[] = '1 = 0';
            } else {
                $conditions[] = sprintf('%shospital_id IN (:hospital_ids)', $prefix);
                $params['hospital_ids'] = $ids;
                $types['hospital_ids'] = ArrayParameterType::INTEGER;
            }
        }

        if (null !== $scope->dispatchAreaId) {
            $conditions[] = self::dispatchAreaPredicate($prefix, $dispatchAreaMatch);
            $params['dispatch_area_id'] = $scope->dispatchAreaId;
        }

        if (null !== $scope->locationCodes) {
            $locationCodes = array_map(intval(...), $scope->locationCodes);
            $conditions[] = sprintf('%shospital_location_code IN (:location_codes)', $prefix);
            $params['location_codes'] = $locationCodes;
            $types['location_codes'] = ArrayParameterType::INTEGER;
        }

        if (null !== $scope->tierCodes) {
            $tierCodes = array_map(intval(...), $scope->tierCodes);
            $conditions[] = sprintf('%shospital_tier_code IN (:tier_codes)', $prefix);
            $params['tier_codes'] = $tierCodes;
            $types['tier_codes'] = ArrayParameterType::INTEGER;
        }

        if ($drawerFilter instanceof StatisticsDrawerFilter && $drawerFilter->isActive()) {
            [$drawerConditions, $drawerParams] = new ProjectionDrawerFilterSql()->apply($drawerFilter, $tableAlias);
            $conditions = [...$conditions, ...$drawerConditions];
            $params = [...$params, ...$drawerParams];
        }

        return [implode(' AND ', $conditions), $params, $types];
    }

    public static function isImpossibleScope(StatisticsScopeCriteria $scope, ?int $originStateId = null): bool
    {
        if (null !== $originStateId) {
            return false;
        }

        return \is_array($scope->hospitalIds) && [] === $scope->hospitalIds;
    }

    private static function dispatchAreaPredicate(string $prefix, CaseFlowDispatchAreaMatch $match): string
    {
        $origin = sprintf('%sdispatch_area_id = :dispatch_area_id', $prefix);
        $catchment = sprintf(
            '%shospital_id IN (SELECT h_scope.id FROM hospital h_scope WHERE h_scope.dispatch_area_id = :dispatch_area_id)',
            $prefix,
        );

        return match ($match) {
            CaseFlowDispatchAreaMatch::Origin => $origin,
            CaseFlowDispatchAreaMatch::Catchment => $catchment,
            CaseFlowDispatchAreaMatch::Related => sprintf('(%s OR %s)', $origin, $catchment),
        };
    }
}
