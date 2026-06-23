<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\DTO;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;

final readonly class AnalysisRunResult
{
    /**
     * @param list<AnalysisMetricKey> $metricKeys
     * @param list<AnalysisResultRow> $rows
     */
    public function __construct(
        public string $title,
        public array $metricKeys,
        public AnalysisMetricKey $visualMetricKey,
        public AnalysisAxisRef $rowAxis,
        public ?AnalysisAxisRef $columnAxis,
        public array $rows,
        public AnalysisTotals $totals,
    ) {
    }

    public function hasSeries(): bool
    {
        return $this->hasColumnAxis();
    }

    public function hasColumnAxis(): bool
    {
        return $this->columnAxis instanceof AnalysisAxisRef
            || array_any($this->rows, fn (AnalysisResultRow $row): bool => $row->hasSeries());
    }

    public function totalFor(AnalysisMetricKey $metricKey): int|float|null
    {
        return $this->totals->grandFor($metricKey);
    }

    public function primaryTotal(): float
    {
        $total = $this->totalFor($this->visualMetricKey);

        return null === $total ? 0.0 : (float) $total;
    }
}
