<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\DTO\PivotTableAxes;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\User\Domain\Entity\User;

final class StatisticsContextFactory
{
    public function create(
        ?User $user,
        StatisticsFilter $filter,
        ?PivotTableAxes $pivotAxes = null,
        ?string $pivotRows = null,
        ?string $pivotCols = null,
        ?string $pivotMeasure = null,
        ?StatisticsFilter $comparisonFilter = null,
    ): StatisticsContext {
        return new StatisticsContext(
            $user,
            $filter,
            $pivotAxes,
            $pivotRows,
            $pivotCols,
            $pivotMeasure,
            $comparisonFilter,
        );
    }
}
