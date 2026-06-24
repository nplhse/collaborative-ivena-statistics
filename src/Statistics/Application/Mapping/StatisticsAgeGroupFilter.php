<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

/**
 * Age-group filter values for drawer and explorer filters.
 *
 * Aggregate buckets (under_18, over_80) complement the decade buckets from {@see DimensionRegistry}.
 */
final class StatisticsAgeGroupFilter
{
    public const string UNDER_18 = 'under_18';
    public const string OVER_80 = 'over_80';

    /** @var list<string> */
    public const array AGGREGATE_KEYS = [
        self::UNDER_18,
        self::OVER_80,
    ];

    /** @var array<string, string> */
    public const array AGGREGATE_TRANSLATION_KEYS = [
        self::UNDER_18 => 'statistics.distribution.age.under_18',
        self::OVER_80 => 'statistics.distribution.age.over_80',
    ];

    public static function isAggregate(string $key): bool
    {
        return \in_array($key, self::AGGREGATE_KEYS, true);
    }

    public static function bucketTranslationKey(string $bucketKey): string
    {
        return self::AGGREGATE_TRANSLATION_KEYS[$bucketKey] ?? 'label.age_group.'.$bucketKey;
    }

    public static function sqlCondition(string $ageColumn, string $key): ?string
    {
        return match ($key) {
            self::UNDER_18 => sprintf('%s IS NOT NULL AND %s < 18', $ageColumn, $ageColumn),
            self::OVER_80 => sprintf('%s >= 80', $ageColumn),
            '0_18' => sprintf('%s IS NOT NULL AND %s <= 18', $ageColumn, $ageColumn),
            '19_29' => sprintf('%s >= 19 AND %s <= 29', $ageColumn, $ageColumn),
            '30_39' => sprintf('%s >= 30 AND %s <= 39', $ageColumn, $ageColumn),
            '40_49' => sprintf('%s >= 40 AND %s <= 49', $ageColumn, $ageColumn),
            '50_59' => sprintf('%s >= 50 AND %s <= 59', $ageColumn, $ageColumn),
            '60_69' => sprintf('%s >= 60 AND %s <= 69', $ageColumn, $ageColumn),
            '70_79' => sprintf('%s >= 70 AND %s <= 79', $ageColumn, $ageColumn),
            '80_89' => sprintf('%s >= 80 AND %s <= 89', $ageColumn, $ageColumn),
            '90_99' => sprintf('%s >= 90 AND %s <= 99', $ageColumn, $ageColumn),
            default => null,
        };
    }
}
