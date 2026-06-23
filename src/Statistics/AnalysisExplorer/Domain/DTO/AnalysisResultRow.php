<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\DTO;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;

final readonly class AnalysisResultRow
{
    /**
     * @param array<string, int|float|null> $metricValues keyed by explorer metric value
     */
    public function __construct(
        public string $bucket,
        public string $bucketLabel,
        public ?string $seriesKey,
        public ?string $seriesLabel,
        public array $metricValues,
        public ?BoxPlotStats $boxPlot = null,
    ) {
    }

    public function hasSeries(): bool
    {
        return null !== $this->seriesKey;
    }

    public function valueFor(AnalysisMetricKey $metricKey): int|float|null
    {
        return $this->metricValues[$metricKey->value] ?? null;
    }

    public function visualValue(AnalysisMetricKey $visualMetricKey): float
    {
        if ($visualMetricKey->isDistributionProfile() && $this->boxPlot instanceof BoxPlotStats) {
            return $this->boxPlot->median ?? 0.0;
        }

        $value = $this->valueFor($visualMetricKey);

        return null === $value ? 0.0 : (float) $value;
    }
}
