<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisTotals;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerMetricCategory;

final readonly class AnalysisTotalsCalculator
{
    public function __construct(
        private ExplorerMetricCatalog $metricCatalog,
    ) {
    }

    /**
     * @param list<AnalysisResultRow> $rows
     * @param list<AnalysisMetricKey> $metricKeys
     */
    public function calculate(array $rows, array $metricKeys): AnalysisTotals
    {
        $grand = [];
        $byRow = [];
        $byColumn = [];

        foreach ($metricKeys as $metricKey) {
            $grand[$metricKey->value] = $this->isSummable($metricKey) ? 0.0 : null;
        }

        foreach ($rows as $row) {
            $colKey = $row->seriesKey ?? '';

            foreach ($metricKeys as $metricKey) {
                if (!$this->isSummable($metricKey)) {
                    continue;
                }

                $value = $row->valueFor($metricKey);
                if (null === $value) {
                    continue;
                }

                $floatValue = (float) $value;
                $grand[$metricKey->value] = ($grand[$metricKey->value] ?? 0.0) + $floatValue;

                $byRow[$row->bucket][$metricKey->value] = ($byRow[$row->bucket][$metricKey->value] ?? 0.0) + $floatValue;

                if ('' !== $colKey) {
                    $byColumn[$colKey][$metricKey->value] = ($byColumn[$colKey][$metricKey->value] ?? 0.0) + $floatValue;
                }
            }
        }

        foreach ($metricKeys as $metricKey) {
            if (AnalysisMetricKey::PercentOfTotal !== $metricKey) {
                continue;
            }

            $key = $metricKey->value;
            if (!\array_key_exists($key, $grand)) {
                continue;
            }

            $value = $grand[$key];
            if (null === $value) {
                continue;
            }

            $grand[$key] = round($value, 2);
        }

        return new AnalysisTotals(
            grand: $grand,
            byRow: $byRow,
            byColumn: $byColumn,
        );
    }

    private function isSummable(AnalysisMetricKey $metricKey): bool
    {
        if (!$this->metricCatalog->has($metricKey)) {
            return AnalysisMetricKey::AllocationCount === $metricKey;
        }

        $category = $this->metricCatalog->get($metricKey)->metricCategory();

        return ExplorerMetricCategory::Count === $category
            || ExplorerMetricCategory::Distribution === $category;
    }
}
