<?php

declare(strict_types=1);

namespace App\Kpi\Application\Contract;

use App\Kpi\Application\DTO\KpiScheduledAggregationResult;

interface KpiScheduledAggregationRunnerInterface
{
    public function run(): KpiScheduledAggregationResult;
}
