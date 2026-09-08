<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Query;

use App\Analytics\Domain\AnalyticsCalendar;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class AnalyticsDayAggregationQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function countRequests(\DateTimeImmutable $day): int
    {
        [$from, $to] = AnalyticsCalendar::dayBounds($day);

        return $this->toInt($this->connection->fetchOne(
            'SELECT COUNT(*) FROM analytics_request WHERE occurred_at >= :from AND occurred_at < :to',
            $this->boundParams($from, $to),
            $this->boundTypes(),
        ));
    }

    public function countEvents(\DateTimeImmutable $day): int
    {
        [$from, $to] = AnalyticsCalendar::dayBounds($day);

        return $this->toInt($this->connection->fetchOne(
            'SELECT COUNT(*) FROM analytics_product_event WHERE occurred_at >= :from AND occurred_at < :to',
            $this->boundParams($from, $to),
            $this->boundTypes(),
        ));
    }

    /**
     * @return list<array{
     *     featureArea: string,
     *     routeName: string|null,
     *     isAuthenticated: bool,
     *     userRole: string|null,
     *     requestCount: int,
     *     errorCount: int,
     *     sumDurationMs: int,
     *     sumDbQueryCount: int,
     *     sumDbTimeMs: int
     * }>
     */
    public function fetchRequestDaily(\DateTimeImmutable $day): array
    {
        [$from, $to] = AnalyticsCalendar::dayBounds($day);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT feature_area,
                       route_name,
                       is_authenticated,
                       user_role,
                       COUNT(*)::int AS request_count,
                       SUM(CASE WHEN http_status >= 400 THEN 1 ELSE 0 END)::int AS error_count,
                       COALESCE(SUM(duration_ms), 0)::int AS sum_duration_ms,
                       COALESCE(SUM(db_query_count), 0)::int AS sum_db_query_count,
                       COALESCE(SUM(db_time_ms), 0)::int AS sum_db_time_ms
                FROM analytics_request
                WHERE occurred_at >= :from
                  AND occurred_at < :to
                GROUP BY feature_area, route_name, is_authenticated, user_role
                SQL,
            $this->boundParams($from, $to),
            $this->boundTypes(),
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'featureArea' => (string) $row['feature_area'],
                'routeName' => null !== $row['route_name'] ? (string) $row['route_name'] : null,
                'isAuthenticated' => (bool) $row['is_authenticated'],
                'userRole' => null !== $row['user_role'] ? (string) $row['user_role'] : null,
                'requestCount' => $this->toInt($row['request_count'] ?? 0),
                'errorCount' => $this->toInt($row['error_count'] ?? 0),
                'sumDurationMs' => $this->toInt($row['sum_duration_ms'] ?? 0),
                'sumDbQueryCount' => $this->toInt($row['sum_db_query_count'] ?? 0),
                'sumDbTimeMs' => $this->toInt($row['sum_db_time_ms'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{
     *     eventName: string,
     *     featureArea: string|null,
     *     userRole: string,
     *     eventCount: int
     * }>
     */
    public function fetchEventDaily(\DateTimeImmutable $day): array
    {
        [$from, $to] = AnalyticsCalendar::dayBounds($day);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT event_name,
                       feature_area,
                       COALESCE(context->>'user_role', 'unknown') AS user_role,
                       COUNT(*)::int AS event_count
                FROM analytics_product_event
                WHERE occurred_at >= :from
                  AND occurred_at < :to
                GROUP BY event_name, feature_area, COALESCE(context->>'user_role', 'unknown')
                SQL,
            $this->boundParams($from, $to),
            $this->boundTypes(),
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'eventName' => (string) $row['event_name'],
                'featureArea' => null !== $row['feature_area'] ? (string) $row['feature_area'] : null,
                'userRole' => (string) ($row['user_role'] ?? 'unknown'),
                'eventCount' => $this->toInt($row['event_count'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{paramName: string, usageCount: int}>
     */
    public function fetchFilterParams(\DateTimeImmutable $day): array
    {
        [$from, $to] = AnalyticsCalendar::dayBounds($day);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT param_name,
                       COUNT(*)::int AS usage_count
                FROM analytics_request r
                CROSS JOIN LATERAL jsonb_array_elements_text(r.query_param_names::jsonb) AS param_name
                WHERE r.occurred_at >= :from
                  AND r.occurred_at < :to
                  AND jsonb_typeof(r.query_param_names::jsonb) = 'array'
                GROUP BY param_name
                SQL,
            $this->boundParams($from, $to),
            $this->boundTypes(),
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'paramName' => (string) $row['param_name'],
                'usageCount' => $this->toInt($row['usage_count'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{featureArea: string, withFilters: int, withoutFilters: int}>
     */
    public function fetchFilterAreas(\DateTimeImmutable $day): array
    {
        [$from, $to] = AnalyticsCalendar::dayBounds($day);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT feature_area,
                       COUNT(*) FILTER (
                           WHERE jsonb_typeof(query_param_names::jsonb) = 'array'
                             AND jsonb_array_length(query_param_names::jsonb) > 0
                       )::int AS with_filters,
                       COUNT(*) FILTER (
                           WHERE jsonb_typeof(query_param_names::jsonb) <> 'array'
                              OR jsonb_array_length(query_param_names::jsonb) = 0
                       )::int AS without_filters
                FROM analytics_request
                WHERE occurred_at >= :from
                  AND occurred_at < :to
                  AND feature_area IN ('analysis', 'statistics', 'explore', 'dashboard')
                GROUP BY feature_area
                SQL,
            $this->boundParams($from, $to),
            $this->boundTypes(),
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'featureArea' => (string) $row['feature_area'],
                'withFilters' => $this->toInt($row['with_filters'] ?? 0),
                'withoutFilters' => $this->toInt($row['without_filters'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{fromRoute: string, toRoute: string, transitionCount: int}>
     */
    public function fetchTransitions(\DateTimeImmutable $day): array
    {
        [$from, $to] = AnalyticsCalendar::dayBounds($day);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                WITH ordered AS (
                    SELECT session_key,
                           route_name,
                           occurred_at,
                           LEAD(route_name) OVER (PARTITION BY session_key ORDER BY occurred_at, id) AS next_route
                    FROM analytics_request
                    WHERE occurred_at >= :from
                      AND occurred_at < :to
                      AND session_key IS NOT NULL
                      AND route_name IS NOT NULL
                )
                SELECT route_name AS from_route,
                       next_route AS to_route,
                       COUNT(*)::int AS transition_count
                FROM ordered
                WHERE next_route IS NOT NULL
                  AND next_route <> route_name
                GROUP BY route_name, next_route
                SQL,
            $this->boundParams($from, $to),
            $this->boundTypes(),
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'fromRoute' => (string) $row['from_route'],
                'toRoute' => (string) $row['to_route'],
                'transitionCount' => $this->toInt($row['transition_count'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{routeName: string, kind: string, sessionCount: int}>
     */
    public function fetchSessionBoundaries(\DateTimeImmutable $day): array
    {
        [$from, $to] = AnalyticsCalendar::dayBounds($day);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                WITH ranked AS (
                    SELECT session_key,
                           route_name,
                           ROW_NUMBER() OVER (PARTITION BY session_key ORDER BY occurred_at ASC, id ASC) AS rn_first,
                           ROW_NUMBER() OVER (PARTITION BY session_key ORDER BY occurred_at DESC, id DESC) AS rn_last
                    FROM analytics_request
                    WHERE occurred_at >= :from
                      AND occurred_at < :to
                      AND session_key IS NOT NULL
                      AND route_name IS NOT NULL
                )
                SELECT route_name, 'entry' AS kind, COUNT(*)::int AS session_count
                FROM ranked
                WHERE rn_first = 1
                GROUP BY route_name
                UNION ALL
                SELECT route_name, 'exit' AS kind, COUNT(*)::int AS session_count
                FROM ranked
                WHERE rn_last = 1
                GROUP BY route_name
                SQL,
            $this->boundParams($from, $to),
            $this->boundTypes(),
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'routeName' => (string) $row['route_name'],
                'kind' => (string) $row['kind'],
                'sessionCount' => $this->toInt($row['session_count'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return array{dau: int, distinctVisitors: int, distinctSessions: int}|null
     */
    public function fetchUniques(\DateTimeImmutable $day): ?array
    {
        [$from, $to] = AnalyticsCalendar::dayBounds($day);

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT COUNT(DISTINCT analytics_user_key) FILTER (WHERE analytics_user_key IS NOT NULL)::int AS dau,
                       COUNT(DISTINCT visitor_key) FILTER (WHERE visitor_key IS NOT NULL)::int AS distinct_visitors,
                       COUNT(DISTINCT session_key) FILTER (WHERE session_key IS NOT NULL)::int AS distinct_sessions,
                       COUNT(*)::int AS request_count
                FROM analytics_request
                WHERE occurred_at >= :from
                  AND occurred_at < :to
                SQL,
            $this->boundParams($from, $to),
            $this->boundTypes(),
        );

        if (false === $row || 0 === $this->toInt($row['request_count'] ?? 0)) {
            return null;
        }

        return [
            'dau' => $this->toInt($row['dau'] ?? 0),
            'distinctVisitors' => $this->toInt($row['distinct_visitors'] ?? 0),
            'distinctSessions' => $this->toInt($row['distinct_sessions'] ?? 0),
        ];
    }

    /**
     * Oldest completed calendar days that still have raw rows and no aggregation marker.
     *
     * @return list<\DateTimeImmutable>
     */
    public function findUnaggregatedCompletedDates(int $limit, \DateTimeImmutable $before): array
    {
        $today = AnalyticsCalendar::startOfToday();
        $beforeDay = AnalyticsCalendar::startOfDay($before);

        $rows = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT d AS date
                FROM (
                    SELECT DISTINCT CAST(occurred_at AS date) AS d
                    FROM analytics_request
                    WHERE occurred_at < :today
                    UNION
                    SELECT DISTINCT CAST(occurred_at AS date) AS d
                    FROM analytics_product_event
                    WHERE occurred_at < :today
                ) days
                WHERE d < :before
                  AND d NOT IN (SELECT date FROM analytics_aggregation_run)
                ORDER BY d ASC
                LIMIT :limit
                SQL,
            [
                'today' => $today->format('Y-m-d H:i:s'),
                'before' => $beforeDay->format('Y-m-d'),
                'limit' => $limit,
            ],
            [
                'today' => ParameterType::STRING,
                'before' => ParameterType::STRING,
                'limit' => ParameterType::INTEGER,
            ],
        );

        $dates = [];
        foreach ($rows as $value) {
            $dates[] = AnalyticsCalendar::startOfDay(new \DateTimeImmutable((string) $value, AnalyticsCalendar::timezone()));
        }

        return $dates;
    }

    /**
     * @return array{from: string, to: string}
     */
    private function boundParams(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{from: ParameterType, to: ParameterType}
     */
    private function boundTypes(): array
    {
        return [
            'from' => ParameterType::STRING,
            'to' => ParameterType::STRING,
        ];
    }

    private function toInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}
