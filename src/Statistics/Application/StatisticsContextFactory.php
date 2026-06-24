<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\User\Domain\Entity\User;

final class StatisticsContextFactory
{
    public function create(
        ?User $user,
        StatisticsFilter $filter,
        ?StatisticsFilter $comparisonFilter = null,
        ?StatisticsDrawerFilter $drawerFilter = null,
    ): StatisticsContext {
        return new StatisticsContext(
            $user,
            $filter,
            $comparisonFilter,
            $drawerFilter,
        );
    }
}
