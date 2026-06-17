<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;

final readonly class MonthlyReminderComparisonFilterFactory
{
    public function createPrimaryFilter(
        int $hospitalId,
        StatisticsFilterPeriod $period,
        ?int $referenceYear,
        ?int $referenceMonth,
    ): StatisticsFilter {
        return new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $hospitalId,
            null,
            $period,
            $referenceYear,
            $referenceMonth,
        );
    }
}
