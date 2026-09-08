<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Query;

use App\Analytics\Domain\AnalyticsCalendar;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

final readonly class AnalyticsReportingQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function countSince(\DateTimeImmutable $from): int
    {
        $params = $this->windowParams($from);

        $aggregated = $this->toInt($this->connection->fetchOne(
            <<<'SQL'
                SELECT COALESCE(SUM(request_count), 0)
                FROM analytics_request_daily d
                WHERE d.date >= :fromDate
                  AND d.date < :today
                  AND EXISTS (
                      SELECT 1 FROM analytics_aggregation_run r
                      WHERE r.date = d.date
                  )
                SQL,
            $params,
            $this->windowTypes(),
        ));

        $raw = $this->toInt($this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM analytics_request req
                WHERE req.occurred_at >= :from
                  AND CAST(req.occurred_at AS date) NOT IN (
                      SELECT date FROM analytics_aggregation_run
                      WHERE date >= :fromDate AND date < :today
                  )
                SQL,
            $params,
            $this->windowTypes(),
        ));

        return $aggregated + $raw;
    }

    /**
     * @return list<array{featureArea: string, requestCount: int, sharePercent: float}>
     */
    public function featureAreaDistributionSince(\DateTimeImmutable $from): array
    {
        $params = $this->windowParams($from);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT feature_area, SUM(request_count)::int AS request_count
                FROM (
                    SELECT d.feature_area, d.request_count
                    FROM analytics_request_daily d
                    WHERE d.date >= :fromDate
                      AND d.date < :today
                      AND EXISTS (
                          SELECT 1 FROM analytics_aggregation_run r WHERE r.date = d.date
                      )
                    UNION ALL
                    SELECT req.feature_area, 1
                    FROM analytics_request req
                    WHERE req.occurred_at >= :from
                      AND CAST(req.occurred_at AS date) NOT IN (
                          SELECT date FROM analytics_aggregation_run
                          WHERE date >= :fromDate AND date < :today
                      )
                ) combined
                GROUP BY feature_area
                ORDER BY request_count DESC
                SQL,
            $params,
            $this->windowTypes(),
        );

        $total = 0;
        foreach ($rows as $row) {
            $total += $this->toInt($row['request_count'] ?? 0);
        }

        $result = [];
        foreach ($rows as $row) {
            $count = $this->toInt($row['request_count'] ?? 0);
            $sharePercent = 0.0;
            if ($total > 0) {
                $sharePercent = round(((float) $count / (float) $total) * 100.0, 1);
            }
            $result[] = [
                'featureArea' => (string) $row['feature_area'],
                'requestCount' => $count,
                'sharePercent' => $sharePercent,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{routeName: string, requestCount: int}>
     */
    public function topRoutesSince(\DateTimeImmutable $from, int $limit = 10): array
    {
        $params = $this->windowParams($from);
        $params['limit'] = $limit;
        $types = $this->windowTypes();
        $types['limit'] = Types::INTEGER;

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT route_name, SUM(request_count)::int AS request_count
                FROM (
                    SELECT d.route_name, d.request_count
                    FROM analytics_request_daily d
                    WHERE d.date >= :fromDate
                      AND d.date < :today
                      AND d.route_name IS NOT NULL
                      AND EXISTS (
                          SELECT 1 FROM analytics_aggregation_run r WHERE r.date = d.date
                      )
                    UNION ALL
                    SELECT req.route_name, 1
                    FROM analytics_request req
                    WHERE req.occurred_at >= :from
                      AND req.route_name IS NOT NULL
                      AND CAST(req.occurred_at AS date) NOT IN (
                          SELECT date FROM analytics_aggregation_run
                          WHERE date >= :fromDate AND date < :today
                      )
                ) combined
                GROUP BY route_name
                ORDER BY request_count DESC
                LIMIT :limit
                SQL,
            $params,
            $types,
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'routeName' => (string) $row['route_name'],
                'requestCount' => $this->toInt($row['request_count'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return array{authenticated: int, anonymous: int}
     */
    public function authenticationSplitSince(\DateTimeImmutable $from): array
    {
        $params = $this->windowParams($from);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT is_authenticated, SUM(request_count)::int AS request_count
                FROM (
                    SELECT d.is_authenticated, d.request_count
                    FROM analytics_request_daily d
                    WHERE d.date >= :fromDate
                      AND d.date < :today
                      AND EXISTS (
                          SELECT 1 FROM analytics_aggregation_run r WHERE r.date = d.date
                      )
                    UNION ALL
                    SELECT req.is_authenticated, 1
                    FROM analytics_request req
                    WHERE req.occurred_at >= :from
                      AND CAST(req.occurred_at AS date) NOT IN (
                          SELECT date FROM analytics_aggregation_run
                          WHERE date >= :fromDate AND date < :today
                      )
                ) combined
                GROUP BY is_authenticated
                SQL,
            $params,
            $this->windowTypes(),
        );

        $authenticated = 0;
        $anonymous = 0;
        foreach ($rows as $row) {
            $count = $this->toInt($row['request_count'] ?? 0);
            if ((bool) $row['is_authenticated']) {
                $authenticated = $count;
            } else {
                $anonymous = $count;
            }
        }

        return [
            'authenticated' => $authenticated,
            'anonymous' => $anonymous,
        ];
    }

    /**
     * @return list<array{userRole: string, featureArea: string, requestCount: int}>
     */
    public function roleAreaMatrixSince(\DateTimeImmutable $from): array
    {
        $params = $this->windowParams($from);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT user_role, feature_area, SUM(request_count)::int AS request_count
                FROM (
                    SELECT d.user_role, d.feature_area, d.request_count
                    FROM analytics_request_daily d
                    WHERE d.date >= :fromDate
                      AND d.date < :today
                      AND d.user_role IS NOT NULL
                      AND EXISTS (
                          SELECT 1 FROM analytics_aggregation_run r WHERE r.date = d.date
                      )
                    UNION ALL
                    SELECT req.user_role, req.feature_area, 1
                    FROM analytics_request req
                    WHERE req.occurred_at >= :from
                      AND req.user_role IS NOT NULL
                      AND CAST(req.occurred_at AS date) NOT IN (
                          SELECT date FROM analytics_aggregation_run
                          WHERE date >= :fromDate AND date < :today
                      )
                ) combined
                GROUP BY user_role, feature_area
                ORDER BY request_count DESC
                LIMIT 50
                SQL,
            $params,
            $this->windowTypes(),
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'userRole' => (string) ($row['user_role'] ?? 'unknown'),
                'featureArea' => (string) $row['feature_area'],
                'requestCount' => $this->toInt($row['request_count'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{eventName: string, eventCount: int, uniqueUsers: int}>
     */
    public function topEventsSince(\DateTimeImmutable $from, int $limit = 15): array
    {
        $params = $this->windowParams($from);
        $params['limit'] = $limit;
        $types = $this->windowTypes();
        $types['limit'] = Types::INTEGER;

        $countRows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT event_name, SUM(event_count)::int AS event_count
                FROM (
                    SELECT d.event_name, d.event_count
                    FROM analytics_event_daily d
                    WHERE d.date >= :fromDate
                      AND d.date < :today
                      AND EXISTS (
                          SELECT 1 FROM analytics_aggregation_run r WHERE r.date = d.date
                      )
                    UNION ALL
                    SELECT e.event_name, 1
                    FROM analytics_product_event e
                    WHERE e.occurred_at >= :from
                      AND CAST(e.occurred_at AS date) NOT IN (
                          SELECT date FROM analytics_aggregation_run
                          WHERE date >= :fromDate AND date < :today
                      )
                ) combined
                GROUP BY event_name
                ORDER BY event_count DESC
                LIMIT :limit
                SQL,
            $params,
            $types,
        );

        $uniqueMap = $this->uniqueUsersByEventSince($from);

        $result = [];
        foreach ($countRows as $row) {
            $eventName = (string) $row['event_name'];
            $result[] = [
                'eventName' => $eventName,
                'eventCount' => $this->toInt($row['event_count'] ?? 0),
                'uniqueUsers' => $uniqueMap[$eventName] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{eventName: string, userRole: string, eventCount: int}>
     */
    public function eventsByRoleSince(\DateTimeImmutable $from): array
    {
        $params = $this->windowParams($from);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT event_name, user_role, SUM(event_count)::int AS event_count
                FROM (
                    SELECT d.event_name, d.user_role, d.event_count
                    FROM analytics_event_daily d
                    WHERE d.date >= :fromDate
                      AND d.date < :today
                      AND EXISTS (
                          SELECT 1 FROM analytics_aggregation_run r WHERE r.date = d.date
                      )
                    UNION ALL
                    SELECT e.event_name, COALESCE(e.context->>'user_role', 'unknown'), 1
                    FROM analytics_product_event e
                    WHERE e.occurred_at >= :from
                      AND CAST(e.occurred_at AS date) NOT IN (
                          SELECT date FROM analytics_aggregation_run
                          WHERE date >= :fromDate AND date < :today
                      )
                ) combined
                GROUP BY event_name, user_role
                ORDER BY event_count DESC
                LIMIT 40
                SQL,
            $params,
            $this->windowTypes(),
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'eventName' => (string) $row['event_name'],
                'userRole' => (string) ($row['user_role'] ?? 'unknown'),
                'eventCount' => $this->toInt($row['event_count'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{paramName: string, usageCount: int}>
     */
    public function topFilterParamsSince(\DateTimeImmutable $from, int $limit = 15): array
    {
        $params = $this->windowParams($from);
        $params['limit'] = $limit;
        $types = $this->windowTypes();
        $types['limit'] = Types::INTEGER;

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT param_name, SUM(usage_count)::int AS usage_count
                FROM (
                    SELECT d.param_name, d.usage_count
                    FROM analytics_filter_param_daily d
                    WHERE d.date >= :fromDate
                      AND d.date < :today
                      AND EXISTS (
                          SELECT 1 FROM analytics_aggregation_run r WHERE r.date = d.date
                      )
                    UNION ALL
                    SELECT param_name, 1
                    FROM analytics_request req
                    CROSS JOIN LATERAL jsonb_array_elements_text(req.query_param_names::jsonb) AS param_name
                    WHERE req.occurred_at >= :from
                      AND jsonb_typeof(req.query_param_names::jsonb) = 'array'
                      AND CAST(req.occurred_at AS date) NOT IN (
                          SELECT date FROM analytics_aggregation_run
                          WHERE date >= :fromDate AND date < :today
                      )
                ) combined
                GROUP BY param_name
                ORDER BY usage_count DESC
                LIMIT :limit
                SQL,
            $params,
            $types,
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
     * @return list<array{featureArea: string, withFilters: int, withoutFilters: int, withFiltersPercent: float}>
     */
    public function filterUsageByAreaSince(\DateTimeImmutable $from): array
    {
        $params = $this->windowParams($from);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT feature_area,
                       SUM(with_filters)::int AS with_filters,
                       SUM(without_filters)::int AS without_filters
                FROM (
                    SELECT d.feature_area, d.with_filters, d.without_filters
                    FROM analytics_filter_area_daily d
                    WHERE d.date >= :fromDate
                      AND d.date < :today
                      AND EXISTS (
                          SELECT 1 FROM analytics_aggregation_run r WHERE r.date = d.date
                      )
                    UNION ALL
                    SELECT req.feature_area,
                           CASE WHEN jsonb_typeof(req.query_param_names::jsonb) = 'array'
                                     AND jsonb_array_length(req.query_param_names::jsonb) > 0
                                THEN 1 ELSE 0 END,
                           CASE WHEN jsonb_typeof(req.query_param_names::jsonb) <> 'array'
                                     OR jsonb_array_length(req.query_param_names::jsonb) = 0
                                THEN 1 ELSE 0 END
                    FROM analytics_request req
                    WHERE req.occurred_at >= :from
                      AND req.feature_area IN ('analysis', 'statistics', 'explore', 'dashboard')
                      AND CAST(req.occurred_at AS date) NOT IN (
                          SELECT date FROM analytics_aggregation_run
                          WHERE date >= :fromDate AND date < :today
                      )
                ) combined
                GROUP BY feature_area
                ORDER BY feature_area
                SQL,
            $params,
            $this->windowTypes(),
        );

        $result = [];
        foreach ($rows as $row) {
            $with = $this->toInt($row['with_filters'] ?? 0);
            $without = $this->toInt($row['without_filters'] ?? 0);
            $total = $with + $without;
            $withFiltersPercent = 0.0;
            if ($total > 0) {
                $withFiltersPercent = round(((float) $with / (float) $total) * 100.0, 1);
            }
            $result[] = [
                'featureArea' => (string) $row['feature_area'],
                'withFilters' => $with,
                'withoutFilters' => $without,
                'withFiltersPercent' => $withFiltersPercent,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    private function uniqueUsersByEventSince(\DateTimeImmutable $from): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT event_name, COUNT(DISTINCT analytics_user_key)::int AS unique_users
                FROM analytics_product_event
                WHERE occurred_at >= :from
                GROUP BY event_name
                SQL,
            ['from' => $from->format('Y-m-d H:i:s')],
            ['from' => ParameterType::STRING],
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['event_name']] = $this->toInt($row['unique_users'] ?? 0);
        }

        return $map;
    }

    /**
     * @return array{from: string, fromDate: string, today: string}
     */
    private function windowParams(\DateTimeImmutable $from): array
    {
        $today = AnalyticsCalendar::startOfToday();

        return [
            'from' => $from->format('Y-m-d H:i:s'),
            'fromDate' => $from->format('Y-m-d'),
            'today' => $today->format('Y-m-d'),
        ];
    }

    /**
     * @return array{from: ParameterType, fromDate: ParameterType, today: ParameterType}
     */
    private function windowTypes(): array
    {
        return [
            'from' => ParameterType::STRING,
            'fromDate' => ParameterType::STRING,
            'today' => ParameterType::STRING,
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
