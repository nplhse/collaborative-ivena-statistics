<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Expected and observed hospital volume for a period, using the same reference rules as a closure.
 * The reference ends at the period start, so later closures do not enter the denominator.
 */
final readonly class ClosureVolumeHospitalBaseline
{
    public function __construct(
        private ClosureVolumeReferenceConfig $config,
        private ClosureVolumeReferenceCalendar $calendar,
        private ClosureVolumeReferenceSelector $selector,
    ) {
    }

    /**
     * @param array<string, int>  $hourlyCounts key "{urgency}|{stratum}|Y-m-d H:00:00"
     * @param array<string, true> $coveredDays
     *
     * @return array{expected: ?float, observed: ?float, partial: bool}
     */
    public function measure(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $hourlyCounts,
        array $coveredDays,
        ClosureVolumeStratum $stratum,
    ): array {
        $from = ClosureVolumeClock::wall($from);
        $to = ClosureVolumeClock::wall($to);
        $now = ClosureVolumeClock::now();
        if ($to > $now) {
            $to = $now;
        }
        if ($from >= $to) {
            return ['expected' => null, 'observed' => null, 'partial' => false];
        }

        $urgencyCode = $stratum->urgencyCode();
        $urgencies = null === $urgencyCode ? [1, 2, 3] : [$urgencyCode];
        $storage = $stratum->storageStratum();
        $expected = 0.0;
        $observed = 0.0;
        $expectedKnown = false;
        $observedKnown = false;
        $partial = false;
        $buckets = $this->periodBuckets($from, $to);

        foreach ($urgencies as $urgency) {
            $hourly = [];
            $prefix = $urgency.'|'.$storage.'|';
            foreach ($hourlyCounts as $key => $count) {
                if (str_starts_with($key, $prefix)) {
                    $hourly[substr($key, \strlen($prefix))] = $count;
                }
            }
            $slots = $this->calendar->build($from, $this->config->referenceWeeks, $hourly, $coveredDays, []);
            $rates = $this->ratesForSlots($slots);
            foreach ($buckets as $bucket) {
                $rate = $rates[$bucket->weekday.'|'.$bucket->hour];
                $value = $rate->expectedForSeconds($bucket->seconds);
                if (null === $value) {
                    $partial = true;
                } else {
                    $expected += $value;
                    $expectedKnown = true;
                }
                $day = $bucket->start->format('Y-m-d');
                if (!isset($coveredDays[$day])) {
                    $partial = true;
                    continue;
                }
                $hourKey = $bucket->start->format('Y-m-d H:00:00');
                $observed += (float) ($hourly[$hourKey] ?? 0) * ((float) $bucket->seconds / 3600.0);
                $observedKnown = true;
            }
        }

        return [
            'expected' => $expectedKnown ? $expected : null,
            'observed' => $observedKnown ? $observed : null,
            'partial' => $partial,
        ];
    }

    /**
     * @return list<ClosureVolumeBucket>
     */
    private function periodBuckets(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        static $key = '';
        static $buckets = [];
        $next = $from->format('Y-m-d H:i:s').'|'.$to->format('Y-m-d H:i:s');
        if ($key !== $next) {
            $key = $next;
            $buckets = ClosureVolumeClock::split($from, $to);
        }

        return $buckets;
    }

    /**
     * The rate depends only on weekday and hour, so each of the 168 combinations is resolved once.
     *
     * @param list<ClosureVolumeReferenceSlot> $slots
     *
     * @return array<string, ClosureVolumeRate>
     */
    private function ratesForSlots(array $slots): array
    {
        $primaryCount = [];
        $primarySum = [];
        $fallbackCount = [];
        $fallbackSum = [];
        foreach ($slots as $slot) {
            $primary = $slot->weekday.'|'.$slot->hour;
            $primaryCount[$primary] = ($primaryCount[$primary] ?? 0) + 1;
            $primarySum[$primary] = ($primarySum[$primary] ?? 0) + $slot->count;
            $fallback = $slot->weekday.'|'.$slot->dayTimeBucket;
            $fallbackCount[$fallback] = ($fallbackCount[$fallback] ?? 0) + 1;
            $fallbackSum[$fallback] = ($fallbackSum[$fallback] ?? 0) + $slot->count;
        }

        $rates = [];
        for ($weekday = 1; $weekday <= 7; ++$weekday) {
            for ($hour = 0; $hour < 24; ++$hour) {
                $primary = $weekday.'|'.$hour;
                $fallback = $weekday.'|'.ClosureVolumeClock::dayTimeBucket($hour);
                $rates[$primary] = $this->selector->fromTallies(
                    $primaryCount[$primary] ?? 0,
                    $primarySum[$primary] ?? 0,
                    $fallbackCount[$fallback] ?? 0,
                    $fallbackSum[$fallback] ?? 0,
                );
            }
        }

        return $rates;
    }
}
