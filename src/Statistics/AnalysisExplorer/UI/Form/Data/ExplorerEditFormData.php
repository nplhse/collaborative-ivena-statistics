<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form\Data;

use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;

final class ExplorerEditFormData
{
    public function __construct(
        public BenchmarkSelectionSideFormData $scopePeriod = new BenchmarkSelectionSideFormData(),
        public string $dimensionGrain = 'month',
        public string $chartType = 'bar',
    ) {
    }
}
