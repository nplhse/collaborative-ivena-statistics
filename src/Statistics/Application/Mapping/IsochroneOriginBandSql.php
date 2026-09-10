<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

/**
 * 10-minute destination-isochrone bands for the origin heatmap.
 *
 * Boundaries match {@see StatisticsTransportTimeBucketSql} for the mapped range:
 * [0, 10), [10, 20), [20, 30), [30, 40), [40, 50). Times of 50 minutes and above
 * stay in {@see self::BEYOND_MAX} because stored isochrones stop at 50 minutes.
 */
final class IsochroneOriginBandSql
{
    public const string UNKNOWN = 'unknown';

    public const string BEYOND_MAX = 'beyond_max';

    public const int INTERVAL_MINUTES = 10;

    /** @var list<int> */
    public const array DISPLAY_BAND_MINUTES = [10, 20, 30, 40, 50];

    public const string CASE_EXPRESSION = <<<'SQL'
CASE
    WHEN transport_time_minutes IS NULL OR transport_time_minutes < 0 THEN 'unknown'
    WHEN transport_time_minutes < 10 THEN '10'
    WHEN transport_time_minutes < 20 THEN '20'
    WHEN transport_time_minutes < 30 THEN '30'
    WHEN transport_time_minutes < 40 THEN '40'
    WHEN transport_time_minutes < 50 THEN '50'
    ELSE 'beyond_max'
END
SQL;

    public static function bandKeyForMinutes(?int $minutes): string
    {
        return match (StatisticsTransportTimeBucketSql::bucketKeyForMinutes($minutes)) {
            'unknown' => self::UNKNOWN,
            'under_10' => '10',
            '10_20' => '20',
            '20_30' => '30',
            '30_40' => '40',
            '40_50' => '50',
            default => self::BEYOND_MAX,
        };
    }

    public static function minutesForBandKey(string $key): ?int
    {
        if (!is_numeric($key)) {
            return null;
        }

        $minutes = (int) $key;

        return \in_array($minutes, self::DISPLAY_BAND_MINUTES, true) ? $minutes : null;
    }
}
