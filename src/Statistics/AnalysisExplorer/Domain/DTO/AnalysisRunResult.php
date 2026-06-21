<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\DTO;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;

final readonly class AnalysisRunResult
{
    /**
     * @param list<AnalysisMetricKey>       $metricKeys
     * @param list<AnalysisResultRow>       $rows
     * @param array<string, int|float|null> $totals     keyed by explorer metric value
     */
    public function __construct(
        public string $title,
        public array $metricKeys,
        public AnalysisMetricKey $visualMetricKey,
        public AnalysisDimensionKey $dimensionKey,
        public ?AnalysisDimensionGrain $timeGrain,
        public array $rows,
        public array $totals,
    ) {
    }

    public function hasSeries(): bool
    {
        return array_any($this->rows, fn ($row) => $row->hasSeries());
    }

    public function totalFor(AnalysisMetricKey $metricKey): int|float|null
    {
        return $this->totals[$metricKey->value] ?? null;
    }

    public function primaryTotal(): float
    {
        $total = $this->totalFor($this->visualMetricKey);

        return null === $total ? 0.0 : (float) $total;
    }
}
