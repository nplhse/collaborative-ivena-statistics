<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application\DTO;

final readonly class BenchmarkHeatmapData
{
    /**
     * @param list<string>      $rowLabels
     * @param list<string>      $columnLabels
     * @param list<list<float>> $deltaMatrix
     * @param list<list<float>> $primaryShareMatrix
     * @param list<list<float>> $comparisonShareMatrix
     */
    public function __construct(
        public array $rowLabels,
        public array $columnLabels,
        public array $deltaMatrix,
        public array $primaryShareMatrix,
        public array $comparisonShareMatrix,
        public float $maxAbsDelta,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], [], [], [], 0.0);
    }
}
