<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

/**
 * Age-group filter values for drawer and explorer filters.
 *
 * Every value excludes cases without a recorded age (`age IS NULL`); those only count when no age group is selected.
 *
 * The filter offers named groups only (#1799); the age buckets of {@see StatisticsAgeGroupBucketSql} are an Explorer
 * dimension, not filter values.
 */
final class StatisticsAgeGroupFilter
{
    public const string UNDER_18 = 'under_18';
    public const string ADULTS = 'adults';
    public const string OVER_65 = 'over_65';
    public const string OVER_80 = 'over_80';

    /** @var list<string> */
    public const array AGGREGATE_KEYS = [
        self::UNDER_18,
        self::ADULTS,
        self::OVER_65,
        self::OVER_80,
    ];

    /** @var array<string, string> */
    public const array AGGREGATE_TRANSLATION_KEYS = [
        self::UNDER_18 => 'statistics.distribution.age.under_18',
        self::ADULTS => 'statistics.distribution.age.adults',
        self::OVER_65 => 'statistics.distribution.age.over_65',
        self::OVER_80 => 'statistics.distribution.age.over_80',
    ];

    /**
     * Keys the age-group filter understands: the named groups (#1799). Every other value, including the age
     * buckets of the Explorer dimension, its `unknown` bucket and the pre-#1040 keys, is unknown and sets no age
     * restriction (#1724).
     */
    public static function isKnown(string $key): bool
    {
        return self::isAggregate($key);
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
        return match ($key) {
            self::UNDER_18 => sprintf('%s IS NOT NULL AND %s < 18', $ageColumn, $ageColumn),
            self::ADULTS => sprintf('%s IS NOT NULL AND %s >= 18', $ageColumn, $ageColumn),
            self::OVER_65 => sprintf('%s >= 65', $ageColumn),
            self::OVER_80 => sprintf('%s >= 80', $ageColumn),
            default => null,
        };
    }
}
