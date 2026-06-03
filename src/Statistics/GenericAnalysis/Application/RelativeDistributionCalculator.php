<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResultRow;

/**
 * Adds relative distribution fields to absolute aggregation rows.
 */
final class RelativeDistributionCalculator
{
    /**
     * @return list<array{row: AnalysisResultRow, percent_of_total: float, percent_of_bucket: float}>
     */
    public function enrich(AnalysisResult $result): array
    {
        $grandTotal = $result->grandTotal;
        $bucketTotals = $this->bucketTotals($result->rows);

        $enriched = [];
        foreach ($result->rows as $row) {
            $bucketKey = $this->key($row->bucket);
            $bucketTotal = $bucketTotals[$bucketKey] ?? 0;

            $enriched[] = [
                'row' => $row,
                'percent_of_total' => $this->percent($row->value, $grandTotal),
                'percent_of_bucket' => $this->percent($row->value, $bucketTotal),
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
            $totals[$key] = ($totals[$key] ?? 0) + $row->value;
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
