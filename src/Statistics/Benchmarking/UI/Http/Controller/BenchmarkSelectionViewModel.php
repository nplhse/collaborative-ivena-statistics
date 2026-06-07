<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Http\Controller;

use App\Statistics\UI\Http\Controller\OverviewPeriodViewModel;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModel;

final readonly class BenchmarkSelectionViewModel
{
    public function __construct(
        public StatisticsPageViewModel $primaryPageViewModel,
        public OverviewPeriodViewModel $primaryPeriodViewModel,
        public StatisticsPageViewModel $comparisonPageViewModel,
        public OverviewPeriodViewModel $comparisonPeriodViewModel,
    ) {
    }
}
