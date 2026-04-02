<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

/**
 * Stabile Integer-Codes für Alterskohorten (GROUP BY / ORDER BY in SQL).
 *
 * -1 unbekannt (NULL), 0 &lt;18, 1 18–29, …, 8 90–99; Alter ≥100 liegt im selben Bucket wie 90–99.
 */
final class AgeCohortBucketExpression
{
    public const int UNKNOWN = -1;

    public const int UNDER_18 = 0;

    /**
     * @return list<int>
     */
    public static function orderedBucketCodes(): array
    {
        return [-1, 0, 1, 2, 3, 4, 5, 6, 7, 8];
    }

    /**
     * SQL-Ausdruck für allocation_stats_projection.age (keine User-Eingaben).
     */
    public static function sql(string $ageColumn = 'age'): string
    {
        return 'CASE '
            .'WHEN '.$ageColumn.' IS NULL THEN '.self::UNKNOWN.' '
            .'WHEN '.$ageColumn.' < 18 THEN '.self::UNDER_18.' '
            .'WHEN '.$ageColumn.' < 30 THEN 1 '
            .'WHEN '.$ageColumn.' < 40 THEN 2 '
            .'WHEN '.$ageColumn.' < 50 THEN 3 '
            .'WHEN '.$ageColumn.' < 60 THEN 4 '
            .'WHEN '.$ageColumn.' < 70 THEN 5 '
            .'WHEN '.$ageColumn.' < 80 THEN 6 '
            .'WHEN '.$ageColumn.' < 90 THEN 7 '
            .'ELSE 8 END';
    }
}
