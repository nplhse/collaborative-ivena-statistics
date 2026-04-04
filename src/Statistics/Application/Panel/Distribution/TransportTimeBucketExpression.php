<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

/**
 * Integer codes for transport_time_minutes buckets (GROUP BY / ORDER BY).
 *
 * 0–6 minute ranges; 8 = unknown (NULL); negative values map to bucket 6 (over 60).
 */
final class TransportTimeBucketExpression
{
    public const int UNKNOWN = 8;

    public const int UNDER_10 = 0;

    public const int MIN_10_TO_20 = 1;

    public const int MIN_20_TO_30 = 2;

    public const int MIN_30_TO_40 = 3;

    public const int MIN_40_TO_50 = 4;

    public const int MIN_50_TO_60 = 5;

    public const int OVER_60 = 6;

    /**
     * @return list<int>
     */
    public static function orderedBucketCodes(): array
    {
        return [0, 1, 2, 3, 4, 5, 6, self::UNKNOWN];
    }

    /**
     * SQL expression for allocation_stats_projection.transport_time_minutes (no user input).
     */
    public static function sql(string $column = 'transport_time_minutes'): string
    {
        return 'CASE '
            .'WHEN '.$column.' IS NULL THEN '.self::UNKNOWN.' '
            .'WHEN '.$column.' < 0 THEN '.self::OVER_60.' '
            .'WHEN '.$column.' < 10 THEN '.self::UNDER_10.' '
            .'WHEN '.$column.' < 20 THEN '.self::MIN_10_TO_20.' '
            .'WHEN '.$column.' < 30 THEN '.self::MIN_20_TO_30.' '
            .'WHEN '.$column.' < 40 THEN '.self::MIN_30_TO_40.' '
            .'WHEN '.$column.' < 50 THEN '.self::MIN_40_TO_50.' '
            .'WHEN '.$column.' < 60 THEN '.self::MIN_50_TO_60.' '
            .'ELSE '.self::OVER_60.' END';
    }

    /**
     * Mirrors {@see self::sql()} for tests and in-app classification (keep branches aligned).
     */
    public static function classifyMinutes(?int $minutes): int
    {
        if (null === $minutes) {
            return self::UNKNOWN;
        }
        if ($minutes < 0) {
            return self::OVER_60;
        }
        if ($minutes < 10) {
            return self::UNDER_10;
        }
        if ($minutes < 20) {
            return self::MIN_10_TO_20;
        }
        if ($minutes < 30) {
            return self::MIN_20_TO_30;
        }
        if ($minutes < 40) {
            return self::MIN_30_TO_40;
        }
        if ($minutes < 50) {
            return self::MIN_40_TO_50;
        }
        if ($minutes < 60) {
            return self::MIN_50_TO_60;
        }

        return self::OVER_60;
    }
}
