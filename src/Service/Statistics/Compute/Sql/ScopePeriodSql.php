<?php

declare(strict_types=1);

namespace App\Service\Statistics\Compute\Sql;

use App\Model\Scope;

trait ScopePeriodSql
{
    /**
     * @return array{sql:string, params: array<string, string|int|float|bool|null>}
     */
    private function buildScopeWhere(Scope $scope): array
    {
        return match ($scope->scopeType) {
            'public' => [
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
            'day' => [
                'sql' => 'period_day(arrival_at) = :period_key::date',
                'params' => ['period_key' => $scope->periodKey],
            ],
            'week' => [
                'sql' => 'period_week(arrival_at) = :period_key::date',
                'params' => ['period_key' => $scope->periodKey],
            ],
            'month' => [
                'sql' => 'period_month(arrival_at) = :period_key::date',
                'params' => ['period_key' => $scope->periodKey],
            ],
            'quarter' => [
                'sql' => 'period_quarter(arrival_at) = :period_key::date',
                'params' => ['period_key' => $scope->periodKey],
            ],
            'year' => [
                'sql' => 'period_year(arrival_at) = :period_key::date',
                'params' => ['period_key' => $scope->periodKey],
            ],
            default => throw new \RuntimeException('Unknown granularity '.$scope->granularity),
        };
    }
}
