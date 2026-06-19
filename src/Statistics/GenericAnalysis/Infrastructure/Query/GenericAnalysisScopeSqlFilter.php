<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use Doctrine\DBAL\ArrayParameterType;

/**
 * @phpstan-type WhereBuildResult array{0: list<string>, 1: array<string, mixed>}
 */
final class GenericAnalysisScopeSqlFilter
{
    private const string TABLE = 'allocation_stats_projection';

    /**
     * @return WhereBuildResult
     */
    public function applyScopeAndPeriod(
        StatisticsScopeCriteria $scope,
        StatisticsPeriodBounds $period,
    ): array {
        $conditions = ['1 = 1'];
        $params = [];

        if ($period->from instanceof \DateTimeImmutable) {
            $conditions[] = 'created_at >= :period_from';
            $params['period_from'] = $period->from->format('Y-m-d H:i:s');
        }

        if ($period->toExclusive instanceof \DateTimeImmutable) {
            $conditions[] = 'created_at < :period_to_exclusive';
            $params['period_to_exclusive'] = $period->toExclusive->format('Y-m-d H:i:s');
        }

        if (\is_array($scope->hospitalIds)) {
            if ([] === $scope->hospitalIds) {
                $conditions[] = '1 = 0';
            } else {
                $conditions[] = 'hospital_id IN (:scope_hospital_ids)';
                $params['scope_hospital_ids'] = $scope->hospitalIds;
            }
        }

        if (null !== $scope->locationCodes) {
            $conditions[] = 'hospital_location_code IN (:scope_location_codes)';
            $params['scope_location_codes'] = $scope->locationCodes;
        }

        if (null !== $scope->tierCodes) {
            $conditions[] = 'hospital_tier_code IN (:scope_tier_codes)';
            $params['scope_tier_codes'] = $scope->tierCodes;
        }

        return [$conditions, $params];
    }

    public function tableName(): string
    {
        return self::TABLE;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function parameterTypes(array $params): array
    {
        $types = [];

        if (isset($params['scope_hospital_ids'])) {
            $types['scope_hospital_ids'] = ArrayParameterType::INTEGER;
        }
        if (isset($params['scope_location_codes'])) {
            $types['scope_location_codes'] = ArrayParameterType::INTEGER;
        }
        if (isset($params['scope_tier_codes'])) {
            $types['scope_tier_codes'] = ArrayParameterType::INTEGER;
        }

        return $types;
    }
}
