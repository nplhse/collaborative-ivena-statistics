<?php

declare(strict_types=1);

namespace App\Analytics\Application\Contract;

use App\Analytics\Application\DTO\AnalyticsScheduledAggregationResult;

interface AnalyticsScheduledAggregationRunnerInterface
{
    public function run(): AnalyticsScheduledAggregationResult;
}
