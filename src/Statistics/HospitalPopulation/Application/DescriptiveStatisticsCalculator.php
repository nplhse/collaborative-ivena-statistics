<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application;

final readonly class DescriptiveStatisticsCalculator
{
    /**
     * @param list<int|float> $values
     */
    public function calculate(array $values): DTO\DescriptiveStats
    {
        if ([] === $values) {
            return new DTO\DescriptiveStats(
                count: 0,
                sum: null,
                minimum: null,
                maximum: null,
                mean: null,
                median: null,
                standardDeviation: null,
                p10: null,
                p25: null,
                p75: null,
                p95: null,
            );
        }

        $sorted = $values;
        sort($sorted, SORT_NUMERIC);
        $count = \count($sorted);
        $mean = array_sum($sorted) / (float) $count;

        return new DTO\DescriptiveStats(
            count: $count,
            sum: (int) array_sum($sorted),
            minimum: (int) $sorted[0],
            maximum: (int) $sorted[$count - 1],
            mean: $mean,
            median: $this->percentile($sorted, 0.5),
            standardDeviation: $this->standardDeviation($sorted, $mean),
            p10: $this->percentile($sorted, 0.1),
            p25: $this->percentile($sorted, 0.25),
            p75: $this->percentile($sorted, 0.75),
            p95: $this->percentile($sorted, 0.95),
        );
    }

    /**
     * @param list<int|float> $sorted
     */
    private function percentile(array $sorted, float $quantile): float
    {
        $count = \count($sorted);
        if (1 === $count) {
            return (float) $sorted[0];
        }

        $index = ((float) $count - 1.0) * $quantile;
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) {
            return (float) $sorted[$lower];
        }

        $weight = $index - (float) $lower;

        return (float) $sorted[$lower] * (1.0 - $weight) + (float) $sorted[$upper] * $weight;
    }

    /**
     * @param list<int|float> $values
     */
    private function standardDeviation(array $values, float $mean): float
    {
        $count = \count($values);
        if ($count <= 1) {
            return 0.0;
        }

        $variance = 0.0;
        foreach ($values as $value) {
            $delta = (float) $value - $mean;
            $variance += $delta * $delta;
        }

        return sqrt($variance / (float) $count);
    }
}
