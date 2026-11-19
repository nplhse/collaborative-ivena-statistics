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
     * Daily, weekly and monthly users per aggregated day from `$from` up to `$toExclusive` (Berlin dates), keyed by
     * `Y-m-d`. DAU is 0 for an aggregated day without requests; WAU/MAU are null when their window could not be
     * counted. Days without an aggregation run are missing.
     *
     * @return array<string, array{dau: int, wau: ?int, mau: ?int}>
     */
    public function activeUsersByDay(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT r.date AS date, COALESCE(u.dau, 0) AS dau, a.wau AS wau, a.mau AS mau
                FROM analytics_aggregation_run r
                LEFT JOIN analytics_uniques_daily u ON u.date = r.date
                LEFT JOIN analytics_active_users_daily a ON a.date = r.date
                WHERE r.date >= :from AND r.date < :to
                ORDER BY r.date
                SQL,
            ['from' => $from->format('Y-m-d'), 'to' => $toExclusive->format('Y-m-d')],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['date']] = [
                'dau' => $this->toInt($row['dau']),
                'wau' => null === $row['wau'] ? null : $this->toInt($row['wau']),
                'mau' => null === $row['mau'] ? null : $this->toInt($row['mau']),
            ];
        }

        return $result;
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
