<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Unions overlapping closure hours of the same population before summing.
 * Several hospitals are summed as numerators; shares are not averaged.
 */
final class ClosureVolumeBurdenAggregator
{
    /**
     * @param list<ClosureVolumeBurdenSlice> $slices
     */
    public function aggregate(array $slices): ClosureVolumeBurdenTotals
    {
        /** @var array<string, ClosureVolumeBurdenSlice> $unique */
        $unique = [];
        foreach ($slices as $slice) {
            if ($slice->overlapSeconds <= 0) {
                continue;
            }
            $key = $slice->hospitalId.'|'.$slice->specialityId.'|'.$slice->departmentId.'|'.$slice->urgencyCode.'|'.$slice->stratum.'|'.$slice->bucketStart;
            $existing = $unique[$key] ?? null;
            if (!$existing instanceof ClosureVolumeBurdenSlice || $slice->referenceCutoff < $existing->referenceCutoff) {
                $unique[$key] = $slice;
            }
        }

        $observed = 0.0;
        $expected = 0.0;
        $observedKnown = false;
        $expectedKnown = false;
        $partialReference = false;
        $partialObservation = false;
        foreach ($unique as $slice) {
            $fraction = $slice->bucketSeconds > 0 ? (float) $slice->overlapSeconds / (float) $slice->bucketSeconds : 0.0;
            if (null === $slice->expectedArea) {
                $partialReference = true;
            } else {
                $expected += $slice->expectedArea * $fraction;
                $expectedKnown = true;
            }
            if (null === $slice->observedArea) {
                $partialObservation = true;
            } else {
                $observed += $slice->observedArea * $fraction;
                $observedKnown = true;
            }
        }

        $observedValue = $observedKnown ? $observed : null;
        $expectedValue = $expectedKnown ? $expected : null;

        return new ClosureVolumeBurdenTotals(
            $observedValue,
            $expectedValue,
            ClosureVolumeDeviation::absolute($observedValue, $expectedValue),
            ClosureVolumeDeviation::relative($observedValue, $expectedValue),
            $partialReference,
            $partialObservation,
            \count($unique),
        );
    }
}
