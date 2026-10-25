<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Reference slots of one weekday and hour (primary) and of its day-time bucket (fallback).
 * Blocked slots were covered and inside the window but closed for the series.
 */
final readonly class ClosureVolumeReferenceTally
{
    public function __construct(
        public int $primaryCount,
        public int $primarySum,
        public int $primaryBlocked,
        public int $fallbackCount,
        public int $fallbackSum,
        public int $fallbackBlocked,
    ) {
    }
}
