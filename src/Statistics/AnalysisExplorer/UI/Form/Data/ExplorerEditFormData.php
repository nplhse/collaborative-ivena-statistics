<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form\Data;

use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;

final class ExplorerEditFormData
{
    public function __construct(
        public StatisticsScopePeriodFormData $scopePeriod = new StatisticsScopePeriodFormData(),
        public string $rowDimension = 'time',
        public ?string $rowGrain = 'month',
        public ?string $columnDimension = null,
        public ?string $columnGrain = null,
        public string $metric = 'allocation_count',
        public bool $showPercentOfTotal = false,
        public string $chartType = 'bar',
        public string $tableLayout = 'flat',
        public string $chartRowLimit = 'all',
    ) {
    }
}
