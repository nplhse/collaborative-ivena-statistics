<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\IndicationDashboard;

/**
 * Clinical age groups for the indication dashboard (0–17, 18–39, 40–59, 60–79, 80+).
 */
final class IndicationDashboardAgeBucketSql
{
    public const string BUCKET_KEYS = '0_17,18_39,40_59,60_79,80_plus,unknown';

    public const string CASE_EXPRESSION = <<<'SQL'
CASE
    WHEN age IS NULL THEN 'unknown'
    WHEN age <= 17 THEN '0_17'
    WHEN age <= 39 THEN '18_39'
    WHEN age <= 59 THEN '40_59'
    WHEN age <= 79 THEN '60_79'
    ELSE '80_plus'
END
SQL;
}
