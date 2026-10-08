<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

final readonly class ClosureVolumeWindowAggregator
{
    /**
     * @param list<ClosureVolumeHourDraft> $drafts already limited to one storage stratum and the selected urgencies
     *
     * @return list<ClosureVolumeWindowResult>
     */
    public function aggregate(
        array $drafts,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        \DateTimeImmutable $now,
        bool $applicable,
    ): array {
        $startsAt = ClosureVolumeClock::wall($startsAt);
        $endsAt = ClosureVolumeClock::wall($endsAt);
        $now = ClosureVolumeClock::wall($now);
        $ongoing = $endsAt > $now;
        $results = [];
        foreach (ClosureVolumeReferenceConfig::WINDOW_HOURS as $hours) {
            $results[] = $this->window(
                'pre_'.$hours,
                $drafts,
                $startsAt->modify(sprintf('-%d hours', $hours)),
                $startsAt,
                $applicable,
                false,
            );
        }
        $duringEnd = $ongoing ? $now : $endsAt;
        $results[] = $this->window('during', $drafts, $startsAt, $endsAt, $applicable, false, $duringEnd);
        foreach (ClosureVolumeReferenceConfig::WINDOW_HOURS as $hours) {
            $results[] = $ongoing
                ? $this->ongoing('post_'.$hours)
                : $this->window(
                    'post_'.$hours,
                    $drafts,
                    $endsAt,
                    $endsAt->modify(sprintf('+%d hours', $hours)),
                    $applicable,
                    false,
                );
        }

        return $results;
    }

    /**
     * @param list<ClosureVolumeWindowResult> $windows
     *
     * @return array{0: ClosureVolumeWindowResult, 1: ClosureVolumeWindowResult}|null
     */
    public function comparison(array $windows): ?array
    {
        $pre = $this->longestClean($windows, 'pre_');
        $post = $this->longestClean($windows, 'post_');
        if (!$pre instanceof ClosureVolumeWindowResult || !$post instanceof ClosureVolumeWindowResult) {
            return null;
        }

        return [$pre, $post];
    }

    /**
     * @param list<ClosureVolumeWindowResult> $windows
     */
    private function longestClean(array $windows, string $prefix): ?ClosureVolumeWindowResult
    {
        foreach ([12, 6, 3, 2, 1] as $hours) {
            foreach ($windows as $window) {
                if ($window->kind !== $prefix.$hours) {
                    continue;
                }
                if ($window->complete && !$window->influenced && $window->computable && ClosureVolumeQuality::Reliable === $window->quality) {
                    return $window;
                }
                if ($window->complete && !$window->influenced && $window->computable && ClosureVolumeQuality::Limited === $window->quality) {
                    return $window;
                }
            }
        }

        return null;
    }

    /**
     * @param list<ClosureVolumeHourDraft> $drafts
     */
    private function window(
        string $kind,
        array $drafts,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        bool $applicable,
        bool $ongoing,
        ?\DateTimeImmutable $observedUntil = null,
    ): ClosureVolumeWindowResult {
        if (!$applicable) {
            return $this->empty($kind, ClosureVolumeQuality::NotApplicable, 0);
        }
        $requested = $this->seconds($start, $end);
        $selected = [];
        foreach ($drafts as $draft) {
            if (str_starts_with($kind, 'pre') && $draft->inClosure) {
                continue;
            }
            if (str_starts_with($kind, 'post') && $draft->inClosure) {
                continue;
            }
            if ('during' === $kind && !$draft->inClosure) {
                continue;
            }
            if ($draft->bucketStart >= $start && $draft->bucketEnd <= $end) {
                $selected[] = $draft;
            }
        }
        if ([] === $selected) {
            return $this->empty($kind, $requested > 0 ? ClosureVolumeQuality::Incomplete : ClosureVolumeQuality::Reliable, $requested);
        }

        $area = $this->sumArea($selected);
        $hospital = $this->sumHospital($selected);
        $evaluable = $this->evaluableSeconds($selected);
        $influenced = false;
        $insufficient = false;
        $limited = false;
        $uncovered = false;
        foreach ($selected as $draft) {
            $influenced = $influenced || $draft->influenced;
            $insufficient = $insufficient || ClosureVolumeQuality::Insufficient->value === $draft->quality;
            $limited = $limited || ClosureVolumeQuality::Limited->value === $draft->quality;
            $uncovered = $uncovered || null === $draft->observedArea;
        }
        $complete = $evaluable === $requested && !$uncovered;
        $expectedArea = $insufficient ? null : $area['expected'];
        $expectedHospital = $insufficient ? null : $hospital['expected'];
        $observedArea = $uncovered ? null : $area['observed'];
        $observedHospital = $uncovered ? null : $hospital['observed'];
        $quality = $this->quality($complete, $influenced, $insufficient, $limited, $ongoing);
        if ($observedUntil instanceof \DateTimeImmutable && $observedUntil < $end) {
            $complete = false;
            if (ClosureVolumeQuality::Reliable === $quality || ClosureVolumeQuality::Limited === $quality) {
                $quality = ClosureVolumeQuality::Incomplete;
            }
        }

        return new ClosureVolumeWindowResult(
            $kind,
            $observedArea,
            $expectedArea,
            $observedHospital,
            $expectedHospital,
            ClosureVolumeDeviation::absolute($observedArea, $expectedArea),
            ClosureVolumeDeviation::relative($observedArea, $expectedArea),
            $evaluable,
            $requested,
            $influenced && !str_starts_with($kind, 'during'),
            $complete,
            null !== $expectedArea && null !== $observedArea,
            $quality,
        );
    }

    private function ongoing(string $kind): ClosureVolumeWindowResult
    {
        return $this->empty($kind, ClosureVolumeQuality::Ongoing, 0);
    }

    private function empty(string $kind, ClosureVolumeQuality $quality, int $requested): ClosureVolumeWindowResult
    {
        return new ClosureVolumeWindowResult(
            $kind,
            null,
            null,
            null,
            null,
            null,
            null,
            0,
            $requested,
            false,
            false,
            false,
            $quality,
        );
    }

    /**
     * @param list<ClosureVolumeHourDraft> $drafts
     */
    private function evaluableSeconds(array $drafts): int
    {
        $byBucket = [];
        foreach ($drafts as $draft) {
            $key = $draft->bucketStart->getTimestamp().'|'.$draft->bucketEnd->getTimestamp();
            $byBucket[$key] = max($byBucket[$key] ?? 0, $draft->evaluableSeconds);
        }

        return array_sum($byBucket);
    }

    private function seconds(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $seconds = 0;
        foreach (ClosureVolumeClock::split($start, $end) as $bucket) {
            $seconds += $bucket->seconds;
        }

        return $seconds;
    }

    /**
     * @param list<ClosureVolumeHourDraft> $drafts
     *
     * @return array{observed: ?float, expected: ?float}
     */
    private function sumArea(array $drafts): array
    {
        $observed = 0.0;
        $expected = 0.0;
        $observedKnown = true;
        $expectedKnown = true;
        foreach ($drafts as $draft) {
            if (null === $draft->observedArea) {
                $observedKnown = false;
            } else {
                $observed += $draft->observedArea;
            }
            if (null === $draft->expectedArea) {
                $expectedKnown = false;
            } else {
                $expected += $draft->expectedArea;
            }
        }

        return [
            'observed' => $observedKnown ? $observed : null,
            'expected' => $expectedKnown ? $expected : null,
        ];
    }

    /**
     * Hospital totals are stored on every department row. Count each urgency-hour once.
     *
     * @param list<ClosureVolumeHourDraft> $drafts
     *
     * @return array{observed: ?float, expected: ?float}
     */
    private function sumHospital(array $drafts): array
    {
        $seen = [];
        $observed = 0.0;
        $expected = 0.0;
        $observedKnown = true;
        $expectedKnown = true;
        foreach ($drafts as $draft) {
            $key = $draft->urgencyCode.'|'.$draft->bucketStart->format('Y-m-d H:i:s');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (null === $draft->observedHospital) {
                $observedKnown = false;
            } else {
                $observed += $draft->observedHospital;
            }
            if (null === $draft->expectedHospital) {
                $expectedKnown = false;
            } else {
                $expected += $draft->expectedHospital;
            }
        }

        return [
            'observed' => $observedKnown ? $observed : null,
            'expected' => $expectedKnown ? $expected : null,
        ];
    }

    private function quality(bool $complete, bool $influenced, bool $insufficient, bool $limited, bool $ongoing): ClosureVolumeQuality
    {
        if ($ongoing) {
            return ClosureVolumeQuality::Ongoing;
        }
        if (!$complete) {
            return ClosureVolumeQuality::Incomplete;
        }
        if ($influenced) {
            return ClosureVolumeQuality::Influenced;
        }
        if ($insufficient) {
            return ClosureVolumeQuality::Insufficient;
        }
        if ($limited) {
            return ClosureVolumeQuality::Limited;
        }

        return ClosureVolumeQuality::Reliable;
    }
}
