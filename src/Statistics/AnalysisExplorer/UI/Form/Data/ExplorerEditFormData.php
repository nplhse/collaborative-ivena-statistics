<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form\Data;

use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;

final class ExplorerEditFormData
{
    public function __construct(
        public StatisticsScopePeriodFormData $scopePeriod = new StatisticsScopePeriodFormData(),
        public string $dimension = 'time',
        public string $metric = 'allocation_count',
        public ?string $timeGrain = 'month',
        public string $chartType = 'bar',
    ) {
    }
}
