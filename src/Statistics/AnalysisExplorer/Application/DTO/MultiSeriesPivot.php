<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\DTO;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;

final readonly class MultiSeriesPivot
{
    /**
     * @param list<string>                               $bucketOrder
     * @param array<string, string>                      $bucketLabels
     * @param array<string, string>                      $seriesLabels
     * @param array<string, array<string, int>>          $valuesByBucket
     * @param list<string>                               $labels
     * @param list<array{name: string, data: list<int>}> $series
     */
    public function __construct(
        public array $bucketOrder,
        public array $bucketLabels,
        public array $seriesLabels,
        public array $valuesByBucket,
        public array $labels,
        public array $series,
    ) {
    }

    public static function fromResult(AnalysisRunResult $result): self
    {
        /** @var list<string> $labels */
        $labels = [];
        /** @var array<string, string> $labelByBucket */
        $labelByBucket = [];
        /** @var array<string, array<string, int>> $valuesBySeries */
        $valuesBySeries = [];
        /** @var array<string, string> $seriesLabels */
        $seriesLabels = [];
        /** @var array<string, array<string, int>> $valuesByBucket */
        $valuesByBucket = [];

        foreach ($result->rows as $row) {
            self::accumulateRow($row, $labelByBucket, $labels, $valuesBySeries, $seriesLabels, $valuesByBucket);
        }

        $bucketOrder = array_keys($labelByBucket);
        $series = [];

        foreach ($seriesLabels as $seriesKey => $seriesLabel) {
            $data = [];
            foreach ($bucketOrder as $bucket) {
                $data[] = $valuesBySeries[$seriesKey][$bucket] ?? 0;
            }

            $series[] = [
                'name' => $seriesLabel,
                'data' => $data,
            ];
        }

        return new self(
            bucketOrder: $bucketOrder,
            bucketLabels: $labelByBucket,
            seriesLabels: $seriesLabels,
            valuesByBucket: $valuesByBucket,
            labels: $labels,
            series: $series,
        );
    }

    /**
     * @param array<string, string>             $labelByBucket
     * @param list<string>                      $labels
     * @param array<string, array<string, int>> $valuesBySeries
     * @param array<string, string>             $seriesLabels
     * @param array<string, array<string, int>> $valuesByBucket
     */
    private static function accumulateRow(
        AnalysisResultRow $row,
        array &$labelByBucket,
        array &$labels,
        array &$valuesBySeries,
        array &$seriesLabels,
        array &$valuesByBucket,
    ): void {
        if (!isset($labelByBucket[$row->bucket])) {
            $labelByBucket[$row->bucket] = $row->bucketLabel;
            $labels[] = $row->bucketLabel;
        }

        $seriesKey = $row->seriesKey ?? '';
        $seriesLabels[$seriesKey] = $row->seriesLabel ?? $seriesKey;
        $valuesBySeries[$seriesKey][$row->bucket] = $row->value;
        $valuesByBucket[$row->bucket][$seriesKey] = $row->value;
    }
}
