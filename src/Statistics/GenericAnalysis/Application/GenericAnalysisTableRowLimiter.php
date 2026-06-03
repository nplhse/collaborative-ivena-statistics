<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Limits table rows by primary bucket count, aggregating the rest into an "Other" bucket.
 */
final readonly class GenericAnalysisTableRowLimiter
{
    private const string OTHER_BUCKET_KEY = '__other__';

    public function __construct(
        private MetricValueFormatter $metricValueFormatter,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<EnrichedAnalysisRow> $rows
     * @param list<string>              $metricKeys
     *
     * @return array{0: list<EnrichedAnalysisRow>, 1: bool}
     */
    public function limit(array $rows, ?int $cap, int $grandTotal, array $metricKeys): array
    {
        if (null === $cap) {
            return [$rows, false];
        }

        $bucketTotals = $this->bucketTotals($rows);
        if (\count($bucketTotals) <= $cap) {
            return [$rows, false];
        }

        arsort($bucketTotals);
        $topBucketKeys = array_slice(array_keys($bucketTotals), 0, $cap);
        $topBucketKeys = array_fill_keys($topBucketKeys, true);

        /** @var array<string, array{count: int, seriesKey: ?string, seriesLabel: ?string}> $otherBySeries */
        $otherBySeries = [];

        $kept = [];
        foreach ($rows as $row) {
            if (isset($topBucketKeys[$row->bucketKey])) {
                $kept[] = $row;

                continue;
            }

            $seriesKey = $row->seriesKey ?? '';
            if (!isset($otherBySeries[$seriesKey])) {
                $otherBySeries[$seriesKey] = [
                    'count' => 0,
                    'seriesKey' => $row->seriesKey,
                    'seriesLabel' => $row->seriesLabel,
                ];
            }
            $otherBySeries[$seriesKey]['count'] += $row->countValue();
        }

        $otherLabel = $this->translator->trans('stats.generic_analysis.chart.remainder_bucket');
        foreach ($otherBySeries as $aggregate) {
            if ($aggregate['count'] <= 0) {
                continue;
            }

            $kept[] = new EnrichedAnalysisRow(
                bucketKey: self::OTHER_BUCKET_KEY,
                bucketLabel: $otherLabel,
                metrics: ['count' => $aggregate['count']],
                formattedMetrics: [],
                seriesKey: $aggregate['seriesKey'],
                seriesLabel: $aggregate['seriesLabel'],
            );
        }

        return [$this->recomputePercents($kept, $grandTotal, $metricKeys), true];
    }

    /**
     * @param list<EnrichedAnalysisRow> $rows
     *
     * @return array<string, int>
     */
    private function bucketTotals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $totals[$row->bucketKey] = ($totals[$row->bucketKey] ?? 0) + $row->countValue();
        }

        return $totals;
    }

    /**
     * @param list<EnrichedAnalysisRow> $rows
     * @param list<string>              $metricKeys
     *
     * @return list<EnrichedAnalysisRow>
     */
    private function recomputePercents(array $rows, int $grandTotal, array $metricKeys): array
    {
        $includePercentOfTotal = \in_array('percent_of_total', $metricKeys, true);
        $includePercentOfBucket = \in_array('percent_of_bucket', $metricKeys, true);

        $bucketTotals = [];
        if ($includePercentOfBucket) {
            foreach ($rows as $row) {
                $bucketTotals[$row->bucketKey] = ($bucketTotals[$row->bucketKey] ?? 0) + $row->countValue();
            }
        }

        $enriched = [];
        foreach ($rows as $row) {
            $metrics = ['count' => $row->countValue()];
            if ($includePercentOfTotal) {
                $metrics['percent_of_total'] = $this->percent($row->countValue(), $grandTotal);
            }
            if ($includePercentOfBucket) {
                $metrics['percent_of_bucket'] = $this->percent(
                    $row->countValue(),
                    $bucketTotals[$row->bucketKey] ?? 0,
                );
            }

            $enriched[] = new EnrichedAnalysisRow(
                bucketKey: $row->bucketKey,
                bucketLabel: $row->bucketLabel,
                metrics: $metrics,
                formattedMetrics: $this->metricValueFormatter->formatMany($metrics),
                seriesKey: $row->seriesKey,
                seriesLabel: $row->seriesLabel,
            );
        }

        return $enriched;
    }

    private function percent(int $value, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(100 * $value / $denominator, 2);
    }
}
