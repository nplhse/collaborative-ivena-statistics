<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

final readonly class AnalysisResultRow
{
    /**
     * @param array<string, int|float|null> $metrics
     */
    public function __construct(
        public int|string|float|null $bucket,
        public array $metrics,
        public int|string|float|null $series = null,
    ) {
    }

    public function countValue(): int
    {
        return (int) $this->baseMetricValue('count');
    }

    public function baseMetricValue(string $metricKey): int|float
    {
        $value = $this->metrics[$metricKey] ?? 0;

        return is_numeric($value) ? (is_float($value) ? $value : (int) $value) : 0;
    }

    #[\Deprecated(message: "Use countValue() or metrics['count']")]
    public function value(): int
    {
        return $this->countValue();
    }
}
