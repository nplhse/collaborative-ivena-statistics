<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\TransportTimeProfile;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileSliceData;

interface TransportTimeProfileSliceQueryInterface
{
    public function fetch(
        StatisticsScopeCriteria $scope,
        StatisticsPeriodBounds $period,
        ?StatisticsDrawerFilter $drawerFilter = null,
    ): TransportTimeProfileSliceData;
}
