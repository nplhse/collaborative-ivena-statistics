<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Service;

/**
 * Elapsed minutes between two wall-clock timestamps interpreted in Europe/Berlin.
 *
 * Stored closure times are local clock times (TIMESTAMP WITHOUT TIME ZONE).
 * Re-reading them in another PHP timezone must not change the duration, including
 * across the spring-forward hour.
 */
final class ClosureIntervalDuration
{
    public const string TIMEZONE = 'Europe/Berlin';

    public static function minutesBetween(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): int
    {
        $timezone = new \DateTimeZone(self::TIMEZONE);
        $start = new \DateTimeImmutable($startsAt->format('Y-m-d H:i:s'), $timezone);
        $end = new \DateTimeImmutable($endsAt->format('Y-m-d H:i:s'), $timezone);

        return (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);
    }
}
