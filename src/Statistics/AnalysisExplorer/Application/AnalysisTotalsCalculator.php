<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisTotals;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;

final readonly class AnalysisTotalsCalculator
{
    public function __construct(
        private ExplorerMetricSummabilityPolicy $summabilityPolicy,
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
            if ($this->summabilityPolicy->isSummable($metricKey)) {
                $grand[$metricKey->value] = 0.0;

                continue;
            }

            $grand[$metricKey->value] = $this->aggregateNonSummableGrandTotal($metricKey, $rows, $metricKeys);
        }

        foreach ($rows as $row) {
            $colKey = $row->seriesKey ?? '';

            foreach ($metricKeys as $metricKey) {
                if (!$this->summabilityPolicy->isSummable($metricKey)) {
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

            $grand[$key] = 100.0;
        }

        return new AnalysisTotals(
            grand: $grand,
            byRow: $byRow,
            byColumn: $byColumn,
        );
    }

    /**
     * @param list<AnalysisResultRow> $rows
     * @param list<AnalysisMetricKey> $metricKeys
     */
    private function aggregateNonSummableGrandTotal(
        AnalysisMetricKey $metricKey,
        array $rows,
        array $metricKeys,
    ): ?float {
        return match ($metricKey) {
            AnalysisMetricKey::AvgBeds => $this->weightedAverage(
                $rows,
                AnalysisMetricKey::AvgBeds,
                AnalysisMetricKey::HospitalCount,
                $metricKeys,
            ) ?? $this->simpleAverage($rows, AnalysisMetricKey::AvgBeds),
            AnalysisMetricKey::AvgAllocationsPerHospital => $this->weightedAverage(
                $rows,
                AnalysisMetricKey::AvgAllocationsPerHospital,
                AnalysisMetricKey::HospitalCount,
                $metricKeys,
            ) ?? $this->simpleAverage($rows, AnalysisMetricKey::AvgAllocationsPerHospital),
            AnalysisMetricKey::MinBeds,
            AnalysisMetricKey::MinAllocations => $this->extremumAcrossRows($rows, $metricKey, 'min'),
            AnalysisMetricKey::MaxBeds,
            AnalysisMetricKey::MaxAllocations => $this->extremumAcrossRows($rows, $metricKey, 'max'),
            default => null,
        };
    }

    /**
     * @param list<AnalysisResultRow> $rows
     * @param list<AnalysisMetricKey> $metricKeys
     */
    private function weightedAverage(
        array $rows,
        AnalysisMetricKey $valueMetric,
        AnalysisMetricKey $weightMetric,
        array $metricKeys,
    ): ?float {
        if (!\in_array($weightMetric, $metricKeys, true)) {
            return null;
        }

        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($rows as $row) {
            $weight = $row->valueFor($weightMetric);
            $value = $row->valueFor($valueMetric);
            if (null === $weight || null === $value || $weight <= 0) {
                continue;
            }

            $weightedSum += (float) $value * (float) $weight;
            $weightTotal += (float) $weight;
        }

        if ($weightTotal <= 0) {
            return null;
        }

        return $weightedSum / $weightTotal;
    }

    /**
     * @param list<AnalysisResultRow> $rows
     */
    private function simpleAverage(array $rows, AnalysisMetricKey $metricKey): ?float
    {
        $sum = 0.0;
        $count = 0;

        foreach ($rows as $row) {
            $value = $row->valueFor($metricKey);
            if (null === $value) {
                continue;
            }

            $sum += (float) $value;
            ++$count;
        }

        if (0 === $count) {
            return null;
        }

        return $sum / (float) $count;
    }

    /**
     * @param list<AnalysisResultRow> $rows
     */
    private function extremumAcrossRows(array $rows, AnalysisMetricKey $metricKey, string $mode): ?float
    {
        $values = [];
        foreach ($rows as $row) {
            $value = $row->valueFor($metricKey);
            if (null !== $value) {
                $values[] = (float) $value;
            }
        }

        if ([] === $values) {
            return null;
        }

        return 'min' === $mode ? min($values) : max($values);
    }
}
