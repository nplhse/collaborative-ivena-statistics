<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

/**
 * Standard statistics age groups: pediatric 0–17, then 10-year bands through 90–99.
 */
final class StatisticsAgeGroupBucketSql
{
    /** @var list<string> */
    public const array DISPLAY_BUCKET_KEYS = [
        '0_17',
        '18_29',
        '30_39',
        '40_49',
        '50_59',
        '60_69',
        '70_79',
        '80_89',
        '90_99',
    ];

    /** @var list<string> */
    public const array BUCKET_KEYS = [
        ...self::DISPLAY_BUCKET_KEYS,
        'unknown',
    ];

    public const string CASE_EXPRESSION = <<<'SQL'
CASE
    WHEN age IS NULL THEN 'unknown'
    WHEN age <= 17 THEN '0_17'
    WHEN age <= 29 THEN '18_29'
    WHEN age <= 39 THEN '30_39'
    WHEN age <= 49 THEN '40_49'
    WHEN age <= 59 THEN '50_59'
    WHEN age <= 69 THEN '60_69'
    WHEN age <= 79 THEN '70_79'
    WHEN age <= 89 THEN '80_89'
    WHEN age <= 99 THEN '90_99'
    ELSE 'unknown'
END
SQL;
}
