<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

final class ClosureVolumeChartFactory
{
    /**
     * @param list<ClosureVolumeHourDraft> $drafts
     *
     * @return array<string, mixed>
     */
    public function build(array $drafts): array
    {
        /** @var array<string, array{start: \DateTimeImmutable, end: \DateTimeImmutable, observed: float, expected: float, hospitalObserved: ?float, hospitalExpected: ?float, inClosure: bool, influenced: bool, expectedKnown: bool, hospitalExpectedKnown: bool, observedKnown: bool, hospitalObservedKnown: bool}> $grouped */
        $grouped = [];
        foreach ($drafts as $draft) {
            $key = $draft->bucketStart->format('Y-m-d H:i:s');
            $grouped[$key] ??= [
                'start' => $draft->bucketStart,
                'end' => $draft->bucketEnd,
                'observed' => 0.0,
                'expected' => 0.0,
                'hospitalObserved' => null,
                'hospitalExpected' => null,
                'inClosure' => false,
                'influenced' => false,
                'expectedKnown' => true,
                'hospitalExpectedKnown' => true,
                'observedKnown' => true,
                'hospitalObservedKnown' => true,
            ];
            if (null === $draft->observedArea) {
                $grouped[$key]['observedKnown'] = false;
            } else {
                $grouped[$key]['observed'] += $draft->observedArea;
            }
            if (null === $draft->expectedArea) {
                $grouped[$key]['expectedKnown'] = false;
            } else {
                $grouped[$key]['expected'] += $draft->expectedArea;
            }
            $hospitalKey = $draft->urgencyCode.'|'.$key;
            if (!isset($grouped[$key]['seen'][$hospitalKey])) {
                $grouped[$key]['seen'][$hospitalKey] = true;
                if (null === $draft->observedHospital) {
                    $grouped[$key]['hospitalObservedKnown'] = false;
                } else {
                    $grouped[$key]['hospitalObserved'] = ($grouped[$key]['hospitalObserved'] ?? 0.0) + $draft->observedHospital;
                }
                if (null === $draft->expectedHospital) {
                    $grouped[$key]['hospitalExpectedKnown'] = false;
                } else {
                    $grouped[$key]['hospitalExpected'] = ($grouped[$key]['hospitalExpected'] ?? 0.0) + $draft->expectedHospital;
                }
            }
            $grouped[$key]['inClosure'] = $grouped[$key]['inClosure'] || $draft->inClosure;
            $grouped[$key]['influenced'] = $grouped[$key]['influenced'] || $draft->influenced;
        }

        uasort($grouped, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);
        $categories = [];
        $starts = [];
        $observed = [];
        $expected = [];
        $areaRatio = [];
        $hospitalRatio = [];
        $influenced = [];
        $inClosure = [];
        foreach ($grouped as $point) {
            $categories[] = $point['start']->format('d.m. H:i');
            $starts[] = $point['start']->format('Y-m-d H:i:s');
            $observed[] = $point['observedKnown'] ? round($point['observed'], 2) : null;
            $expectedValue = $point['expectedKnown'] ? round($point['expected'], 2) : null;
            $expected[] = $expectedValue;
            $hospitalObserved = $point['hospitalObservedKnown'] ? $point['hospitalObserved'] : null;
            $hospitalExpected = $point['hospitalExpectedKnown'] ? $point['hospitalExpected'] : null;
            $areaRatio[] = $this->ratio($point['observedKnown'] ? $point['observed'] : null, $expectedValue);
            $hospitalRatio[] = $this->ratio($hospitalObserved, $hospitalExpected);
            $influenced[] = $point['influenced'];
            $inClosure[] = $point['inClosure'];
        }

        return [
            'categories' => $categories,
            'starts' => $starts,
            'observed' => $observed,
            'expected' => $expected,
            'areaRatio' => $areaRatio,
            'hospitalRatio' => $hospitalRatio,
            'influenced' => $influenced,
            'closureBands' => $this->bands($inClosure),
        ];
    }

    /**
     * @param list<ClosureVolumeHourDraft> $drafts
     *
     * @return array<string, list<int|float|null>>
     */
    public function buildBarSeries(array $drafts): array
    {
        /** @var array<string, array<string, float>> $byBucket */
        $byBucket = [];
        $bucketOrder = [];
        foreach ($drafts as $draft) {
            $bucketKey = $draft->bucketStart->format('Y-m-d H:i:s');
            $bucketOrder[$bucketKey] = $draft->bucketStart;
            $seriesKey = $this->barSeriesKey($draft);
            if (null === $seriesKey) {
                continue;
            }
            if (null === $draft->observedArea) {
                continue;
            }
            $byBucket[$bucketKey][$seriesKey] = ($byBucket[$bucketKey][$seriesKey] ?? 0.0) + $draft->observedArea;
        }
        uasort($bucketOrder, static fn (\DateTimeImmutable $left, \DateTimeImmutable $right): int => $left <=> $right);
        $series = [];
        foreach (['sk1', 'sk2', 'sk3', 'resus', 'cathlab'] as $key) {
            $series[$key] = [];
        }
        foreach (array_keys($bucketOrder) as $bucketKey) {
            foreach (['sk1', 'sk2', 'sk3', 'resus', 'cathlab'] as $key) {
                $value = $byBucket[$bucketKey][$key] ?? null;
                $series[$key][] = null === $value ? null : round($value, 2);
            }
        }

        return $series;
    }

    private function barSeriesKey(ClosureVolumeHourDraft $draft): ?string
    {
        if ('resus' === $draft->stratum) {
            return 'resus';
        }
        if ('cathlab' === $draft->stratum) {
            return 'cathlab';
        }

        return match ($draft->urgencyCode) {
            1 => 'sk1',
            2 => 'sk2',
            3 => 'sk3',
            default => null,
        };
    }

    private function ratio(?float $observed, ?float $expected): ?float
    {
        $relative = ClosureVolumeDeviation::relative($observed, $expected);
        if (null === $relative) {
            return null;
        }

        return round(($relative + 1.0) * 100.0, 1);
    }

    /**
     * @param list<bool> $flags
     *
     * @return list<array{0: int, 1: int}>
     */
    private function bands(array $flags): array
    {
        $bands = [];
        $start = null;
        foreach ($flags as $index => $flag) {
            if ($flag && null === $start) {
                $start = $index;
            }
            if (!$flag && null !== $start) {
                $bands[] = [$start, $index - 1];
                $start = null;
            }
        }
        if (null !== $start) {
            $bands[] = [$start, \count($flags) - 1];
        }

        return $bands;
    }
}
