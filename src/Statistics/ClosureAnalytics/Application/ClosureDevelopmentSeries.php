<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

use App\Statistics\Application\TimeSeries\TimeSeriesGrain;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureTimeBucket;

/**
 * The rolling 12-month window has a start and no exclusive end. The query only
 * emits months inside the observed import span, so the development chart is
 * completed through the current month with explicit zero buckets.
 */
final class ClosureDevelopmentSeries
{
    /**
     * @param list<ClosureTimeBucket> $buckets
     *
     * @return list<ClosureTimeBucket>
     */
    public static function complete(
        array $buckets,
        ClosureAnalyticsCriteria $criteria,
        ?\DateTimeImmutable $now = null,
    ): array {
        $from = $criteria->period->from;
        if (TimeSeriesGrain::Month !== $criteria->timeSeriesGrain
            || !$from instanceof \DateTimeImmutable
            || $criteria->period->toExclusive instanceof \DateTimeImmutable
        ) {
            return $buckets;
        }

        $now ??= new \DateTimeImmutable('now');
        $cursor = $from->modify('first day of this month')->setTime(0, 0);
        $end = $now->modify('first day of this month')->setTime(0, 0);
        if ($cursor > $end) {
            return $buckets;
        }

        $byKey = [];
        foreach ($buckets as $bucket) {
            $byKey[$bucket->key] = $bucket;
        }

        $filled = [];
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m');
            $filled[] = $byKey[$key] ?? new ClosureTimeBucket($key, 0, 0, 0, 0, 0);
            $cursor = $cursor->modify('+1 month');
        }

        return $filled;
    }
}
