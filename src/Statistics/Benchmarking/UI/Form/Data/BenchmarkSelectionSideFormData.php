<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Form\Data;

use App\Statistics\Application\DTO\StatisticsFilterPeriod;

final class BenchmarkSelectionSideFormData
{
    public function __construct(
        public string $scopeGroup = 'public',
        public ?string $scopeDetail = null,
        public string $period = StatisticsFilterPeriod::AllTime->value,
        public ?int $periodYear = null,
        public ?int $periodQuarter = null,
        public ?int $periodMonth = null,
    ) {
    }
}
