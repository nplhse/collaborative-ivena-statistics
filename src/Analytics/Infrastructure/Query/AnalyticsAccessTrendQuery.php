<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Counted accesses per Berlin calendar day for the long-term access overview (#950).
 *
 * Days with rows in analytics_access_daily come from there. Days without such rows (today, days
 * not aggregated yet) come from raw requests, but only when every request of the day is
 * classified; a day with unclassified requests is left out. No day is read from both sources.
 */
final readonly class AnalyticsAccessTrendQuery
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<array{date: string, authenticated: int, anonymous: int}> ordered by date
     */
    public function dailyAccesses(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
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
                ORDER BY day
                SQL,
        );

        $days = [];
        foreach ($rows as $row) {
            $days[] = [
                'date' => (string) $row['day'],
                'authenticated' => (int) $row['authenticated'],
                'anonymous' => (int) $row['anonymous'],
            ];
        }

        return $days;
    }
}
