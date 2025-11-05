<?php

declare(strict_types=1);

namespace App\Service\Statistics\Compute\Sql;

use App\Model\Scope;
use App\Service\Statistics\Util\Period;

trait ScopePeriodSql
{
    /**
     * @return array{sql:string, params: array<string, string|int|float|bool|null>}
     */
    private function buildScopeWhere(Scope $scope): array
    {
        return match ($scope->scopeType) {
            'public', 'hospital_tier', 'hospital_size', 'hospital_location', 'hospital_cohort' => [
                'sql' => 'TRUE',
                'params' => [],
            ],
            'hospital' => [
                'sql' => 'hospital_id = :scope_id::int',
                'params' => ['scope_id' => $scope->scopeId],
            ],
            'dispatch_area' => [
                'sql' => 'dispatch_area_id = :scope_id::int',
                'params' => ['scope_id' => $scope->scopeId],
            ],
            'state' => [
                'sql' => 'state_id = :scope_id::int',
                'params' => ['scope_id' => $scope->scopeId],
            ],
            default => throw new \RuntimeException('Unknown scopeType '.$scope->scopeType),
        };
    }

    /**
     * @return array{sql:string, params: array<string, string|int|float|bool|null>}
     */
    private function buildPeriodExpr(Scope $scope): array
    {
        return match ($scope->granularity) {
            Period::ALL => [
                'sql' => 'TRUE',
                'params' => [],
            ],
            Period::DAY => [
                'sql' => 'period_day(arrival_at) = :period_key::date',
                'params' => ['period_key' => $scope->periodKey],
            ],
            Period::WEEK => [
                'sql' => 'period_week(arrival_at) = :period_key::date',
                'params' => ['period_key' => $scope->periodKey],
            ],
            Period::MONTH => [
                'sql' => 'period_month(arrival_at) = :period_key::date',
                'params' => ['period_key' => $scope->periodKey],
            ],
            Period::QUARTER => [
                'sql' => 'period_quarter(arrival_at) = :period_key::date',
                'params' => ['period_key' => $scope->periodKey],
            ],
            Period::YEAR => [
                'sql' => 'period_year(arrival_at) = :period_key::date',
                'params' => ['period_key' => $scope->periodKey],
            ],
            default => throw new \RuntimeException('Unknown granularity '.$scope->granularity),
        };
    }
}
