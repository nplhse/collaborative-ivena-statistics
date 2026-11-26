<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Counted accesses per Berlin calendar month for the long-term access overview (#950, #1001).
 *
 * Days with rows in analytics_access_daily come from there. Days without such rows (today, days
 * not aggregated yet) come from raw requests, but only when every request of the day is
 * classified; a day with unclassified requests is left out. No day is read from both sources.
 * The days are summed into months in the database, so the page reads one row per month instead
 * of one per day.
 */
final readonly class AnalyticsAccessTrendQuery
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param \DateTimeImmutable $today Berlin calendar day; later days are not counted
     *
     * @return list<array{month: string, firstDay: string, authenticated: int, anonymous: int}> ordered by month;
     *                                                                                          `month` is `Y-m`, `firstDay` the month's first day with data (`Y-m-d`)
     */
    public function monthlyAccesses(\DateTimeImmutable $today): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                WITH days AS (
                    SELECT d.date AS day,
                           SUM(CASE WHEN d.is_authenticated THEN d.access_count ELSE 0 END) AS authenticated,
                           SUM(CASE WHEN d.is_authenticated THEN 0 ELSE d.access_count END) AS anonymous
                    FROM analytics_access_daily d
                    GROUP BY d.date
                    UNION ALL
                    SELECT CAST(r.occurred_at AS date) AS day,
                           COUNT(*) FILTER (WHERE r.counted_access AND r.is_authenticated) AS authenticated,
                           COUNT(*) FILTER (WHERE r.counted_access AND NOT r.is_authenticated) AS anonymous
                    FROM analytics_request r
                    WHERE CAST(r.occurred_at AS date) NOT IN (SELECT date FROM analytics_access_daily)
                    GROUP BY CAST(r.occurred_at AS date)
                    HAVING COUNT(*) FILTER (WHERE r.counted_access IS NULL) = 0
                )
                SELECT TO_CHAR(day, 'YYYY-MM') AS month,
                       MIN(day) AS first_day,
                       SUM(authenticated) AS authenticated,
                       SUM(anonymous) AS anonymous
                FROM days
                WHERE day <= CAST(:today AS date)
                GROUP BY TO_CHAR(day, 'YYYY-MM')
                ORDER BY month
                SQL,
            ['today' => $today->format('Y-m-d')],
        );

        $months = [];
        foreach ($rows as $row) {
            $months[] = [
                'month' => (string) $row['month'],
                'firstDay' => (string) $row['first_day'],
                'authenticated' => (int) $row['authenticated'],
                'anonymous' => (int) $row['anonymous'],
            ];
        }

        return $months;
    }
}
