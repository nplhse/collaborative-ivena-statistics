<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ChartPrimaryBucketLimiter
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<string>                                     $labels
     * @param list<int|float>                                  $values
     * @param list<array{name: string, data: list<int|float>}> $series
     *
     * @return array{0: list<string>, 1: list<int|float>, 2: list<array{name: string, data: list<int|float>}>}
     */
    public function limit(
        array $labels,
        array $values,
        array $series,
        int $cap,
        bool $includeRemainderBucket = true,
    ): array {
        if (\count($labels) <= $cap) {
            return [$labels, $values, $series];
        }

        $bucketTotals = [];

        if ([] !== $series) {
            foreach ($labels as $labelIndex => $label) {
                $sum = 0.0;
                foreach ($series as $item) {
                    $sum += (float) ($item['data'][$labelIndex] ?? 0);
                }
                $bucketTotals[$label] = $sum;
            }
        } else {
            foreach ($labels as $labelIndex => $label) {
                $bucketTotals[$label] = $values[$labelIndex] ?? 0;
            }
        }

        arsort($bucketTotals);
        $topLabels = array_slice(array_keys($bucketTotals), 0, $cap);

        $restTotal = 0.0;
        foreach ($bucketTotals as $label => $total) {
            if (!\in_array($label, $topLabels, true)) {
                $restTotal += (float) $total;
            }
        }

        $newLabels = $topLabels;
        if ($includeRemainderBucket && $restTotal > 0) {
            $newLabels[] = $this->remainderBucketLabel();
        }

        $newValues = [];
        if ([] === $series) {
            foreach ($topLabels as $label) {
                $newValues[] = $bucketTotals[$label];
            }
            if ($includeRemainderBucket && $restTotal > 0) {
                $newValues[] = $restTotal;
            }

            return [$newLabels, $newValues, $series];
        }

        $newSeries = [];
        foreach ($series as $item) {
            $newData = [];
            foreach ($topLabels as $label) {
                $labelIndex = array_search($label, $labels, true);
                $newData[] = false !== $labelIndex ? ($item['data'][$labelIndex] ?? 0) : 0;
            }
            if ($includeRemainderBucket && $restTotal > 0) {
                $restForSeries = 0.0;
                foreach (array_keys($bucketTotals) as $label) {
                    if (\in_array($label, $topLabels, true)) {
                        continue;
                    }
                    $labelIndex = array_search($label, $labels, true);
                    if (false !== $labelIndex) {
                        $restForSeries += (float) ($item['data'][$labelIndex] ?? 0);
                    }
                }
                $newData[] = $restForSeries;
            }
            $newSeries[] = [
                'name' => $item['name'],
                'data' => $newData,
            ];
        }

        return [$newLabels, $newValues, $newSeries];
    }

    private function remainderBucketLabel(): string
    {
        return $this->translator->trans('stats.generic_analysis.chart.remainder_bucket', [], 'statistics');
    }
}
