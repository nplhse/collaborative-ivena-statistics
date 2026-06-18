<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Form\Data;

final class BenchmarkSelectionFormData
{
    public function __construct(
        public BenchmarkSelectionSideFormData $primary = new BenchmarkSelectionSideFormData(),
        public BenchmarkSelectionSideFormData $comparison = new BenchmarkSelectionSideFormData(),
    ) {
    }
}
