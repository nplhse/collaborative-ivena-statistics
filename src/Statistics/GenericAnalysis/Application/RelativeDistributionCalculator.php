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

        $grandTotal = $result->grandTotal;
        $bucketTotals = $includePercentOfBucket ? $this->bucketTotals($result->rows) : [];

        $enriched = [];
        foreach ($result->rows as $row) {
            $derivedMetrics = [];
            $count = $row->countValue();

            if ($includePercentOfTotal) {
                $derivedMetrics['percent_of_total'] = $this->percent($count, $grandTotal);
            }

            if ($includePercentOfBucket) {
                $bucketKey = $this->key($row->bucket);
                $bucketTotal = $bucketTotals[$bucketKey] ?? 0;
                $derivedMetrics['percent_of_bucket'] = $this->percent($count, $bucketTotal);
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
    private function bucketTotals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $key = $this->key($row->bucket);
            $totals[$key] = ($totals[$key] ?? 0) + $row->countValue();
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
