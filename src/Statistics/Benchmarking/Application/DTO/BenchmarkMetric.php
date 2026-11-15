<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application\DTO;

final readonly class BenchmarkMetric
{
    public function __construct(
        public BenchmarkMetricKey $key,
        public float $primaryValue,
        public float $comparisonValue,
        public float $absoluteDelta,
        public float $relativeDelta,
        public float $ratio,
        public BenchmarkMetricFormat $format,
        public int $primaryNumerator = 0,
        public int $primaryDenominator = 0,
        public int $comparisonNumerator = 0,
        public int $comparisonDenominator = 0,
    ) {
    }

    /**
     * The builders store ratio and relativeDelta as 0.0 when the comparison value is 0; that ratio is
     * undefined, not 0 (#1231). The comparison values are never negative.
     */
    public function hasRatio(): bool
    {
        return $this->comparisonValue > 0.0;
    }
}
