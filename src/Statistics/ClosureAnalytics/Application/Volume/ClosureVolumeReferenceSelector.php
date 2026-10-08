<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

final readonly class ClosureVolumeReferenceSelector
{
    public function __construct(private ClosureVolumeReferenceConfig $config)
    {
    }

    /**
     * @param list<ClosureVolumeReferenceSlot> $slots
     */
    public function select(int $weekday, int $hour, int $dayTimeBucket, array $slots): ClosureVolumeRate
    {
        $primary = array_values(array_filter(
            $slots,
            static fn (ClosureVolumeReferenceSlot $slot): bool => $slot->weekday === $weekday && $slot->hour === $hour,
        ));
        if (\count($primary) >= $this->config->minimumReferenceSlots) {
            return $this->rate($primary, ClosureVolumeReferenceMode::WeekdayHour);
        }

        $fallback = array_values(array_filter(
            $slots,
            static fn (ClosureVolumeReferenceSlot $slot): bool => $slot->weekday === $weekday && $slot->dayTimeBucket === $dayTimeBucket,
        ));
        if (\count($fallback) >= $this->config->minimumReferenceSlots) {
            return $this->rate($fallback, ClosureVolumeReferenceMode::DayTimeBucket);
        }

        return new ClosureVolumeRate(
            null,
            ClosureVolumeReferenceMode::Insufficient,
            \count($fallback),
            $this->sum($fallback),
        );
    }

    public function fromTallies(int $primaryCount, int $primarySum, int $fallbackCount, int $fallbackSum): ClosureVolumeRate
    {
        if ($primaryCount >= $this->config->minimumReferenceSlots) {
            return new ClosureVolumeRate($primarySum / $primaryCount, ClosureVolumeReferenceMode::WeekdayHour, $primaryCount, $primarySum);
        }
        if ($fallbackCount >= $this->config->minimumReferenceSlots) {
            return new ClosureVolumeRate($fallbackSum / $fallbackCount, ClosureVolumeReferenceMode::DayTimeBucket, $fallbackCount, $fallbackSum);
        }

        return new ClosureVolumeRate(null, ClosureVolumeReferenceMode::Insufficient, $fallbackCount, $fallbackSum);
    }

    /**
     * @param list<ClosureVolumeReferenceSlot> $slots
     */
    private function rate(array $slots, ClosureVolumeReferenceMode $mode): ClosureVolumeRate
    {
        $sum = $this->sum($slots);

        return new ClosureVolumeRate($sum / \count($slots), $mode, \count($slots), $sum);
    }

    /**
     * @param list<ClosureVolumeReferenceSlot> $slots
     */
    private function sum(array $slots): int
    {
        $sum = 0;
        foreach ($slots as $slot) {
            $sum += $slot->count;
        }

        return $sum;
    }
}
