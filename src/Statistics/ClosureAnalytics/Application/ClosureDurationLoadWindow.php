<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;

/**
 * Caps the analysis window at the current Europe/Berlin wall clock.
 * Stored closure timestamps are Berlin wall time, and the temporal SQL
 * interprets period bounds the same way.
 */
final class ClosureDurationLoadWindow
{
    public static function cap(ClosureAnalyticsCriteria $criteria, \DateTimeImmutable $now): ClosureAnalyticsCriteria
    {
        $berlin = $now->setTimezone(new \DateTimeZone('Europe/Berlin'));
        $wallClock = new \DateTimeImmutable($berlin->format('Y-m-d H:i:s'));

        return $criteria->withPeriod($criteria->period->intersect(null, $wallClock));
    }
}
