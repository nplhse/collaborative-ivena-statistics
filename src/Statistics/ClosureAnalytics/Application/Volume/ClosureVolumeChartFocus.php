<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Hides the middle of a long closure in the course charts. The charts keep the context outside the
 * closure and {@see ClosureVolumeReferenceConfig::INNER_FOCUS_HOURS} hours after the start and before the end.
 * Key figures and the window table still use the whole closure.
 */
final readonly class ClosureVolumeChartFocus
{
    private function __construct(
        public \DateTimeImmutable $hideFrom,
        public \DateTimeImmutable $hideUntil,
    ) {
    }

    /**
     * Null when the closure is short enough to show completely. A running closure ends at $now for this purpose.
     */
    public static function around(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, \DateTimeImmutable $now): ?self
    {
        $inside = sprintf('%d hours', ClosureVolumeReferenceConfig::INNER_FOCUS_HOURS);
        $end = ClosureVolumeClock::wall($endsAt);
        $current = ClosureVolumeClock::wall($now);
        if ($current < $end) {
            $end = $current;
        }
        $hideFrom = ClosureVolumeClock::wall($startsAt)->modify('+'.$inside);
        $hideUntil = $end->modify('-'.$inside);

        return $hideFrom < $hideUntil ? new self($hideFrom, $hideUntil) : null;
    }

    /**
     * Only buckets that lie completely inside the hidden span are hidden; a bucket on its edge stays visible.
     */
    public function hides(\DateTimeImmutable $bucketStart, \DateTimeImmutable $bucketEnd): bool
    {
        return ClosureVolumeClock::wall($bucketStart) >= $this->hideFrom
            && ClosureVolumeClock::wall($bucketEnd) <= $this->hideUntil;
    }
}
