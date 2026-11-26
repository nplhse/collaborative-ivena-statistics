<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResultRow;

/**
 * Adds relative distribution metrics to absolute aggregation rows.
 */
final class RelativeDistributionCalculator
{
    /**
     * @param list<string> $metricKeys
     *
     * @return list<array{row: AnalysisResultRow, derivedMetrics: array<string, float>}>
     */
    public function enrich(AnalysisResult $result, array $metricKeys): array
    {
        $includePercentOfTotal = \in_array('percent_of_total', $metricKeys, true);
        $includePercentOfBucket = \in_array('percent_of_bucket', $metricKeys, true);
        $includePercentOfSeries = \in_array('percent_of_series', $metricKeys, true);

        $grandTotal = $result->grandTotal;
        $baseMetricKey = $result->distributionBaseMetricKey;
        $bucketTotals = $includePercentOfBucket ? $this->bucketTotals($result->rows, $baseMetricKey) : [];
        // All counted cases of each series, from the full result: display and export limits come later (#1346).
        $seriesTotals = $includePercentOfSeries ? $this->seriesTotals($result->rows, $baseMetricKey) : [];

        $enriched = [];
        foreach ($result->rows as $row) {
            $derivedMetrics = [];
            $baseValue = (int) $row->baseMetricValue($baseMetricKey);

            if ($includePercentOfTotal) {
                $derivedMetrics['percent_of_total'] = $this->percent($baseValue, $grandTotal);
            }

            if ($includePercentOfBucket) {
                $bucketKey = $this->key($row->bucket);
                $bucketTotal = $bucketTotals[$bucketKey] ?? 0;
                $derivedMetrics['percent_of_bucket'] = $this->percent($baseValue, $bucketTotal);
            }

            if ($includePercentOfSeries) {
                $seriesTotal = $seriesTotals[$this->key($row->series)] ?? 0;
                $derivedMetrics['percent_of_series'] = $this->percent($baseValue, $seriesTotal);
            }

            $enriched[] = [
                'row' => $row,
                'derivedMetrics' => $derivedMetrics,
            ];
        }

        return $enriched;
    }

    /**
     * @param list<AnalysisResultRow> $rows
     *
     * @return array<string, int>
     */
    private function bucketTotals(array $rows, string $baseMetricKey): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $key = $this->key($row->bucket);
            $totals[$key] = ($totals[$key] ?? 0) + (int) $row->baseMetricValue($baseMetricKey);
        }

        return $totals;
    }

    /**
     * @param list<AnalysisResultRow> $rows
     *
     * @return array<string, int>
     */
    private function seriesTotals(array $rows, string $baseMetricKey): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $key = $this->key($row->series);
            $totals[$key] = ($totals[$key] ?? 0) + (int) $row->baseMetricValue($baseMetricKey);
        }

        return $totals;
    }

    private function percent(int $value, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(100 * $value / $denominator, 2);
    }

    private function key(int|string|float|null $bucket): string
    {
        if (null === $bucket) {
            return '__null__';
        }

        return (string) $bucket;
    }
}
