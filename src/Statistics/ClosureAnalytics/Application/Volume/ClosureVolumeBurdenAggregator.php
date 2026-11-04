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
     * Slices of one population hour (hospital, speciality, department, metric, bucket start) must arrive
     * next to each other; the query orders them so. Only the current hour is held, so the input can be streamed.
     *
     * @param iterable<ClosureVolumeBurdenSlice> $slices
     */
    public function aggregate(iterable $slices): ClosureVolumeBurdenTotals
    {
        $state = [
            'observed' => 0.0,
            'expected' => 0.0,
            'observedKnown' => false,
            'expectedKnown' => false,
            'partialReference' => false,
            'partialObservation' => false,
            'count' => 0,
        ];
        $currentKey = null;
        $current = null;
        foreach ($slices as $slice) {
            if ($slice->overlapSeconds <= 0) {
                continue;
            }
            $key = $slice->hospitalId.'|'.$slice->specialityId.'|'.$slice->departmentId.'|'.$slice->metric.'|'.$slice->bucketStart;
            if ($key !== $currentKey) {
                if ($current instanceof ClosureVolumeBurdenSlice) {
                    $state = $this->add($state, $current);
                }
                $currentKey = $key;
                $current = $slice;

                continue;
            }
            if ($current instanceof ClosureVolumeBurdenSlice && $slice->referenceCutoff < $current->referenceCutoff) {
                $current = $slice;
            }
        }
        if ($current instanceof ClosureVolumeBurdenSlice) {
            $state = $this->add($state, $current);
        }

        $observedValue = $state['observedKnown'] ? $state['observed'] : null;
        $expectedValue = $state['expectedKnown'] ? $state['expected'] : null;

        return new ClosureVolumeBurdenTotals(
            $observedValue,
            $expectedValue,
            ClosureVolumeDeviation::absolute($observedValue, $expectedValue),
            ClosureVolumeDeviation::relative($observedValue, $expectedValue),
            $state['partialReference'],
            $state['partialObservation'],
            $state['count'],
        );
    }

    /**
     * @param array{observed: float, expected: float, observedKnown: bool, expectedKnown: bool, partialReference: bool, partialObservation: bool, count: int} $state
     *
     * @return array{observed: float, expected: float, observedKnown: bool, expectedKnown: bool, partialReference: bool, partialObservation: bool, count: int}
     */
    private function add(array $state, ClosureVolumeBurdenSlice $slice): array
    {
        $fraction = $slice->bucketSeconds > 0 ? (float) $slice->overlapSeconds / (float) $slice->bucketSeconds : 0.0;
        if (null === $slice->expectedArea) {
            $state['partialReference'] = true;
        } else {
            $state['expected'] += $slice->expectedArea * $fraction;
            $state['expectedKnown'] = true;
        }
        if (null === $slice->observedArea) {
            $state['partialObservation'] = true;
        } else {
            $state['observed'] += $slice->observedArea * $fraction;
            $state['observedKnown'] = true;
        }
        ++$state['count'];

        return $state;
    }
}
