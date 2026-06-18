<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Form\Data;

final class BenchmarkSelectionSideFormData
{
    public function __construct(
        public string $scopeGroup = 'public',
        public ?string $scopeDetail = null,
        public string $period = 'all_time',
        public ?int $periodYear = null,
        public ?int $periodQuarter = null,
        public ?int $periodMonth = null,
    ) {
    }
}
