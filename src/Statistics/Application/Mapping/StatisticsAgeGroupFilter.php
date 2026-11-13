<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

/**
 * Age-group filter values for drawer and explorer filters.
 *
 * Every value excludes cases without a recorded age (`age IS NULL`); those only count when no age group is selected.
 *
 * Aggregate buckets (under_18, adults, over_80) complement the age buckets of {@see StatisticsAgeGroupBucketSql}.
 */
final class StatisticsAgeGroupFilter
{
    public const string UNDER_18 = 'under_18';
    public const string ADULTS = 'adults';
    public const string OVER_80 = 'over_80';

    /** @var list<string> */
    public const array AGGREGATE_KEYS = [
        self::UNDER_18,
        self::ADULTS,
        self::OVER_80,
    ];

    /** @var array<string, string> */
    public const array AGGREGATE_TRANSLATION_KEYS = [
        self::UNDER_18 => 'statistics.distribution.age.under_18',
        self::ADULTS => 'statistics.distribution.age.adults',
        self::OVER_80 => 'statistics.distribution.age.over_80',
    ];

    /**
     * Explorer bucket keys used before #1040, when 18-year-olds counted as minors.
     *
     * Deprecated: old links and stored filters are still mapped to the current buckets; the mapping is removed in v0.4-beta.
     *
     * @var array<string, string>
     */
    public const array LEGACY_KEYS = [
        '0_18' => '0_17',
        '19_29' => '18_29',
    ];

    /**
     * Maps a pre-#1040 bucket key to the current one; other keys are returned unchanged.
     */
    public static function normalizeKey(string $key): string
    {
        return self::LEGACY_KEYS[$key] ?? $key;
    }

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
        return match (self::normalizeKey($key)) {
            self::UNDER_18 => sprintf('%s IS NOT NULL AND %s < 18', $ageColumn, $ageColumn),
            self::ADULTS => sprintf('%s IS NOT NULL AND %s >= 18', $ageColumn, $ageColumn),
            self::OVER_80 => sprintf('%s >= 80', $ageColumn),
            '0_17' => sprintf('%s IS NOT NULL AND %s <= 17', $ageColumn, $ageColumn),
            '18_29' => sprintf('%s >= 18 AND %s <= 29', $ageColumn, $ageColumn),
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
