<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\DTO;

final readonly class EnrichedAnalysisRow
{
    /**
     * @param array<string, int|float|null> $metrics
     * @param array<string, string>         $formattedMetrics
     */
    public function __construct(
        public string $bucketKey,
        public string $bucketLabel,
        public array $metrics,
        public array $formattedMetrics,
        public ?string $seriesKey = null,
        public ?string $seriesLabel = null,
    ) {
    }

    public function baseMetricValue(string $baseMetricKey): int
    {
        return (int) ($this->metrics[$baseMetricKey] ?? 0);
    }

    public function countValue(): int
    {
        return $this->baseMetricValue('count');
    }

    #[\Deprecated(message: "Use countValue() or metrics['count']")]
    public function value(): int
    {
        return $this->countValue();
    }

    #[\Deprecated(message: "Use metrics['percent_of_total']")]
    public function percentOfTotal(): float
    {
        return (float) ($this->metrics['percent_of_total'] ?? 0.0);
    }

    #[\Deprecated(message: "Use metrics['percent_of_bucket']")]
    public function percentOfBucket(): float
    {
        return (float) ($this->metrics['percent_of_bucket'] ?? 0.0);
    }
}
