<?php

declare(strict_types=1);

namespace App\Analytics\Domain;

final class AnalyticsCalendar
{
    public const string TIMEZONE = 'Europe/Berlin';

    public static function timezone(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }

    public static function startOfToday(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', self::timezone());
    }

    public static function yesterday(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('yesterday', self::timezone());
    }

    public static function startOfDay(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), self::timezone());
    }

    public static function daysAgo(int $days): \DateTimeImmutable
    {
        return self::startOfToday()->modify(sprintf('-%d days', $days));
    }

    /**
     * Inclusive start and exclusive end of the Berlin calendar day.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function dayBounds(\DateTimeImmutable $date): array
    {
        $from = self::startOfDay($date);
        $to = $from->modify('+1 day');

        return [$from, $to];
    }
}
