<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality;

use App\Statistics\DataQuality\Dto\AllocationHistogramBucket;
use App\Statistics\DataQuality\Dto\AllocationVolumeResult;

final class AllocationVolumeDataQualityCalculator
{
    /**
     * @param array<int, int>    $allocationCounts hospitalId => count
     * @param array<int, string> $hospitalNames
     */
    public function calculate(array $allocationCounts, array $hospitalNames): AllocationVolumeResult
    {
        $counts = array_values($allocationCounts);
        $participantCount = \count($counts);

        if (0 === $participantCount) {
            return new AllocationVolumeResult(
                DataQualityLevel::Low,
                0,
                0.0,
                0.0,
                0,
                0,
                0,
                0,
                0.0,
                DataQualityThresholds::MIN_ALLOCATIONS_PER_HOSPITAL,
                0,
                [],
                $this->buildHistogram([]),
            );
        }

        sort($counts);
        $totalAllocations = array_sum($counts);
        $atLeastThreshold = 0;

        foreach ($counts as $count) {
            if ($count >= DataQualityThresholds::MIN_ALLOCATIONS_PER_HOSPITAL) {
                ++$atLeastThreshold;
            }
        }

        $share = $atLeastThreshold / $participantCount;
        $hospitalsBelowThreshold = [];

        foreach ($allocationCounts as $hospitalId => $count) {
            if ($count < DataQualityThresholds::MIN_ALLOCATIONS_PER_HOSPITAL) {
                $hospitalsBelowThreshold[] = [
                    'id' => $hospitalId,
                    'name' => $hospitalNames[$hospitalId] ?? (string) $hospitalId,
                    'count' => $count,
                ];
            }
        }

        usort(
            $hospitalsBelowThreshold,
            static fn (array $a, array $b): int => $a['count'] <=> $b['count'],
        );

        return new AllocationVolumeResult(
            DataQualityLevel::fromShareRatio($share),
            $totalAllocations,
            round($totalAllocations / $participantCount, 1),
            (float) $this->percentile($counts, 0.5),
            $this->percentile($counts, 0.25),
            $this->percentile($counts, 0.75),
            $this->percentile($counts, 0.95),
            $counts[$participantCount - 1],
            round($share, 4),
            DataQualityThresholds::MIN_ALLOCATIONS_PER_HOSPITAL,
            $participantCount,
            $hospitalsBelowThreshold,
            $this->buildHistogram($counts),
        );
    }

    /**
     * @param list<int> $sortedCounts
     */
    private function percentile(array $sortedCounts, float $percentile): int
    {
        $count = \count($sortedCounts);
        if (0 === $count) {
            return 0;
        }

        $index = (int) floor($percentile * (float) ($count - 1));

        return $sortedCounts[$index];
    }

    /**
     * @param list<int> $counts
     *
     * @return list<AllocationHistogramBucket>
     */
    private function buildHistogram(array $counts): array
    {
        /** @var list<array{lower: int, upper: ?int}> $bucketRanges */
        $bucketRanges = [
            ['lower' => DataQualityThresholds::ALLOCATION_HISTOGRAM_EDGES[0], 'upper' => DataQualityThresholds::ALLOCATION_HISTOGRAM_EDGES[1]],
            ['lower' => DataQualityThresholds::ALLOCATION_HISTOGRAM_EDGES[1], 'upper' => DataQualityThresholds::ALLOCATION_HISTOGRAM_EDGES[2]],
            ['lower' => DataQualityThresholds::ALLOCATION_HISTOGRAM_EDGES[2], 'upper' => DataQualityThresholds::ALLOCATION_HISTOGRAM_EDGES[3]],
            ['lower' => DataQualityThresholds::ALLOCATION_HISTOGRAM_EDGES[3], 'upper' => null],
        ];
        $buckets = [];

        foreach ($bucketRanges as $range) {
            $lower = $range['lower'];
            $upper = $range['upper'];
            $label = null === $upper
                ? sprintf('%d+', $lower)
                : sprintf('%d–%d', $lower, $upper - 1);

            $bucketCount = 0;
            foreach ($counts as $count) {
                $inBucket = $count >= $lower && (null === $upper || $count < $upper);
                if ($inBucket) {
                    ++$bucketCount;
                }
            }

            $buckets[] = new AllocationHistogramBucket(
                $label,
                $bucketCount,
                $lower >= DataQualityThresholds::MIN_ALLOCATIONS_PER_HOSPITAL,
            );
        }

        return $buckets;
    }
}
