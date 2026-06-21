<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\DTO;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;

final readonly class MultiSeriesPivot
{
    /**
     * @param list<string>                                 $bucketOrder
     * @param array<string, string>                        $bucketLabels
     * @param array<string, string>                        $seriesLabels
     * @param array<string, array<string, float>>          $valuesByBucket
     * @param list<string>                                 $labels
     * @param list<array{name: string, data: list<float>}> $series
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

    public static function fromResult(AnalysisRunResult $result, AnalysisMetricKey $visualMetricKey): self
    {
        /** @var list<string> $labels */
        $labels = [];
        /** @var array<string, string> $labelByBucket */
        $labelByBucket = [];
        /** @var array<string, array<string, float>> $valuesBySeries */
        $valuesBySeries = [];
        /** @var array<string, string> $seriesLabels */
        $seriesLabels = [];
        /** @var array<string, array<string, float>> $valuesByBucket */
        $valuesByBucket = [];

        foreach ($result->rows as $row) {
            self::accumulateRow($row, $visualMetricKey, $labelByBucket, $labels, $valuesBySeries, $seriesLabels, $valuesByBucket);
        }

        $bucketOrder = array_keys($labelByBucket);
        $series = [];

        foreach ($seriesLabels as $seriesKey => $seriesLabel) {
            $data = [];
            foreach ($bucketOrder as $bucket) {
                $data[] = $valuesBySeries[$seriesKey][$bucket] ?? 0.0;
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
     * @param array<string, string>                   $labelByBucket
     * @param list<string>                            $labels
     * @param array<string, array<string, float|int>> $valuesBySeries
     * @param array<string, string>                   $seriesLabels
     * @param array<string, array<string, float|int>> $valuesByBucket
     */
    private static function accumulateRow(
        AnalysisResultRow $row,
        AnalysisMetricKey $visualMetricKey,
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
        $value = $row->visualValue($visualMetricKey);
        $valuesBySeries[$seriesKey][$row->bucket] = $value;
        $valuesByBucket[$row->bucket][$seriesKey] = $value;
    }
}
