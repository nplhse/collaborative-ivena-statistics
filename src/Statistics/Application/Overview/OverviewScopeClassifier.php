<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\DTO\StatisticsFilterScope;

final class OverviewScopeClassifier
{
    public static function isAggregateScope(StatisticsFilterScope $scope): bool
    {
        return match ($scope) {
            StatisticsFilterScope::Public,
            StatisticsFilterScope::State,
            StatisticsFilterScope::DispatchArea,
            StatisticsFilterScope::HospitalCohort => true,
            default => false,
        };
    }
}
