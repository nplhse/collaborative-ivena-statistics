<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

/**
 * Transport duration buckets in minutes (aligned with statistics.distribution.transport_time_bucket.*).
 */
final class StatisticsTransportTimeBucketSql
{
    /** @var list<string> */
    public const array DISPLAY_BUCKET_KEYS = [
        'under_10',
        '10_20',
        '20_30',
        '30_40',
        '40_50',
        '50_60',
        'over_60',
    ];

    public const string CASE_EXPRESSION = <<<'SQL'
CASE
    WHEN transport_time_minutes IS NULL OR transport_time_minutes < 0 THEN 'unknown'
    WHEN transport_time_minutes < 10 THEN 'under_10'
    WHEN transport_time_minutes < 20 THEN '10_20'
    WHEN transport_time_minutes < 30 THEN '20_30'
    WHEN transport_time_minutes < 40 THEN '30_40'
    WHEN transport_time_minutes < 50 THEN '40_50'
    WHEN transport_time_minutes < 60 THEN '50_60'
    ELSE 'over_60'
END
SQL;

    /**
     * PHP mirror of {@see CASE_EXPRESSION} for rounded transport_time_minutes.
     *
     * Boundaries are half-open [N, N+10): 10 minutes is 10_20, not under_10.
     */
    public static function bucketKeyForMinutes(?int $minutes): string
    {
        if (null === $minutes || $minutes < 0) {
            return 'unknown';
        }

        return match (true) {
            $minutes < 10 => 'under_10',
            $minutes < 20 => '10_20',
            $minutes < 30 => '20_30',
            $minutes < 40 => '30_40',
            $minutes < 50 => '40_50',
            $minutes < 60 => '50_60',
            default => 'over_60',
        };
    }

    public static function translationKey(string $bucketKey): string
    {
        return 'statistics.distribution.transport_time_bucket.'.$bucketKey;
    }
}
