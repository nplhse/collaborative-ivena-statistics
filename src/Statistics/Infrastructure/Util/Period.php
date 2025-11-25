<?php

namespace App\Statistics\Infrastructure\Util;

final class Period
{
    public const ALL = 'all';
    public const YEAR = 'year';
    public const QUARTER = 'quarter';
    public const MONTH = 'month';
    public const WEEK = 'week';
    public const DAY = 'day';

    public const ALL_ANCHOR_DATE = '2010-01-01';

    /**
     * @return string[]
     */
    public static function allGranularities(): array
    {
        return [self::ALL, self::YEAR, self::QUARTER, self::MONTH, self::WEEK, self::DAY];
    }

    public static function normalizePeriodKey(string $granularity, string $periodKey): string
    {
        $granularity = strtolower($granularity);

        if (self::ALL === $granularity) {
            return self::ALL_ANCHOR_DATE;
        }

        $dt = self::toDate($periodKey);

        return match ($granularity) {
            self::YEAR => sprintf('%04d-01-01', (int) $dt->format('Y')),
            self::MONTH => sprintf('%04d-%02d-01', (int) $dt->format('Y'), (int) $dt->format('m')),
            default => $dt->format('Y-m-d'),
        };
    }

    /**
     * Returns an anchor date used for generating period grids.
     */
    public static function anchor(string $granularity, string $periodKey): \DateTimeImmutable
    {
        $norm = self::normalizePeriodKey($granularity, $periodKey);

        return self::toDate($norm);
    }

    /**
     * Parses Y-m-d, Y-m, Y, or general date formats into a DateTimeImmutable.
     */
    private static function toDate(string $value): \DateTimeImmutable
    {
        // Exact: YYYY-MM-DD
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($dt instanceof \DateTimeImmutable) {
            return $dt;
        }

        // YYYY-MM -> normalize to first day of the month
        $dt = \DateTimeImmutable::createFromFormat('Y-m', $value);
        if ($dt instanceof \DateTimeImmutable) {
            return $dt->setDate((int) $dt->format('Y'), (int) $dt->format('m'), 1);
        }

        // YYYY -> normalize to January 1st
        $dt = \DateTimeImmutable::createFromFormat('Y', $value);
        if ($dt instanceof \DateTimeImmutable) {
            return $dt->setDate((int) $dt->format('Y'), 1, 1);
        }

        // Fallback: try natural parsing
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return new \DateTimeImmutable('today');
        }
    }
}
