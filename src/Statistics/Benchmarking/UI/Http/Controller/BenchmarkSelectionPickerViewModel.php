<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Http\Controller;

final readonly class BenchmarkSelectionPickerViewModel
{
    /**
     * @param array<string, scalar> $initialParams
     */
    public function __construct(
        public string $baseUrl,
        public array $initialParams,
        public BenchmarkSelectionPickerSideViewModel $primary,
        public BenchmarkSelectionPickerSideViewModel $comparison,
    ) {
    }
}
