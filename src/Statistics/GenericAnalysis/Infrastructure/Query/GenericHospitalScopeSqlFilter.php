<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\AllocationStatsHospitalLocationProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use Doctrine\DBAL\ArrayParameterType;

/**
 * @phpstan-type WhereBuildResult array{0: list<string>, 1: array<string, mixed>}
 */
final class GenericHospitalScopeSqlFilter
{
    /**
     * @return WhereBuildResult
     */
    public function applyHospitalScope(StatisticsScopeCriteria $scope): array
    {
        $conditions = ['1 = 1'];
        $params = [];

        if (\is_array($scope->hospitalIds)) {
            if ([] === $scope->hospitalIds) {
                $conditions[] = '1 = 0';
            } else {
                $conditions[] = 'h.id IN (:scope_hospital_ids)';
                $params['scope_hospital_ids'] = $scope->hospitalIds;
            }
        }

        if (null !== $scope->locationCodes) {
            $locations = [];
            foreach ($scope->locationCodes as $code) {
                $projection = AllocationStatsHospitalLocationProjectionCode::tryFrom($code);
                if (null !== $projection) {
                    $locations[] = $projection->toHospitalLocation()->value;
                }
            }
            if ([] !== $locations) {
                $conditions[] = 'h.location IN (:scope_location_values)';
                $params['scope_location_values'] = $locations;
            }
        }

        if (null !== $scope->tierCodes) {
            $tiers = [];
            foreach ($scope->tierCodes as $code) {
                $projection = AllocationStatsHospitalTierProjectionCode::tryFrom($code);
                if (null !== $projection) {
                    $tiers[] = $projection->toHospitalTier()->value;
                }
            }
            if ([] !== $tiers) {
                $conditions[] = 'h.tier IN (:scope_tier_values)';
                $params['scope_tier_values'] = $tiers;
            }
        }

        return [$conditions, $params];
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
        if (isset($params['scope_location_values'])) {
            $types['scope_location_values'] = ArrayParameterType::STRING;
        }
        if (isset($params['scope_tier_values'])) {
            $types['scope_tier_values'] = ArrayParameterType::STRING;
        }

        return $types;
    }
}
