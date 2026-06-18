<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

/**
 * Precise transport duration in minutes from projection timestamps (for KPI aggregates).
 *
 * Distribution charts continue to use {@see StatisticsTransportTimeBucketSql} on rounded
 * {@code transport_time_minutes}.
 */
final class StatisticsTransportTimeSql
{
    public const string PRECISE_MINUTES_EXPRESSION = 'EXTRACT(EPOCH FROM (arrival_at - created_at)) / 60.0';

    public static function preciseMinutesExpression(?string $tableAlias = null): string
    {
        if (null === $tableAlias || '' === $tableAlias) {
            return self::PRECISE_MINUTES_EXPRESSION;
        }

        return sprintf(
            'EXTRACT(EPOCH FROM (%1$sarrival_at - %1$screated_at)) / 60.0',
            $tableAlias.'.',
        );
    }

    public static function medianPreciseMinutes(?string $tableAlias = null): string
    {
        return sprintf(
            'PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY %s)',
            self::preciseMinutesExpression($tableAlias),
        );
    }

    public static function meanPreciseMinutes(?string $tableAlias = null): string
    {
        return sprintf('AVG(%s)', self::preciseMinutesExpression($tableAlias));
    }
}
