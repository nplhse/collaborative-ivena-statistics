<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\GenericAnalysis\Application\MetricValueFormatter;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;

final readonly class ExplorerTablePercentHelper
{
    public function __construct(
        private MetricRegistry $metricRegistry,
        private MetricValueFormatter $metricValueFormatter,
    ) {
    }

    public function percentOfTotal(int|float|null $value, int|float|null $grandTotal): ?float
    {
        if (null === $value || null === $grandTotal || $grandTotal <= 0) {
            return null;
        }

        return round(100.0 * (float) $value / (float) $grandTotal, 2);
    }

    public function percentOfRow(int|float|null $value, int|float|null $rowTotal): ?float
    {
        if (null === $value || null === $rowTotal || $rowTotal <= 0) {
            return null;
        }

        return round(100.0 * (float) $value / (float) $rowTotal, 2);
    }

    public function formatPercent(?float $percent): string
    {
        if (null === $percent) {
            return '—';
        }

        return $this->metricValueFormatter->format(
            $this->metricRegistry->get(AnalysisMetricKey::PercentOfTotal->registryKey()),
            $percent,
        );
    }
}
