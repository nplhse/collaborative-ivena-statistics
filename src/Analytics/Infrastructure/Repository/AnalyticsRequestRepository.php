<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Repository;

use App\Analytics\Domain\AnalyticsCalendar;
use App\Analytics\Domain\Entity\AnalyticsRequest;
use App\Analytics\Domain\Enum\FeatureArea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsRequest>
 */
final class AnalyticsRequestRepository extends ServiceEntityRepository
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        ManagerRegistry $registry,
    ) {
        parent::__construct($registry, AnalyticsRequest::class);
    }

    public function save(AnalyticsRequest $request, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($request);
        if ($flush) {
            $em->flush();
        }
    }

    public function countSince(\DateTimeImmutable $from): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.occurredAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countToday(): int
    {
        return $this->countSince($this->startOfToday());
    }

    public function countLast7Days(): int
    {
        return $this->countSince($this->daysAgo(6));
    }

    public function countLast30Days(): int
    {
        return $this->countSince($this->daysAgo(29));
    }

    /**
     * @return list<array{routeName: string, requestCount: int}>
     */
    public function topRoutesSince(\DateTimeImmutable $from, int $limit = 10): array
    {
        /** @var list<array{routeName: string|null, requestCount: int|string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.routeName AS routeName', 'COUNT(r.id) AS requestCount')
            ->where('r.occurredAt >= :from')
            ->andWhere('r.routeName IS NOT NULL')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->groupBy('r.routeName')
            ->orderBy('requestCount', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'routeName' => $row['routeName'] ?? '',
                'requestCount' => (int) $row['requestCount'],
            ];
        }

        return $result;
    }

    /**
     * @return list<array{featureArea: string, requestCount: int, sharePercent: float}>
     */
    public function featureAreaDistributionSince(\DateTimeImmutable $from): array
    {
        /** @var list<array{featureArea: FeatureArea|string, requestCount: int|string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.featureArea AS featureArea', 'COUNT(r.id) AS requestCount')
            ->where('r.occurredAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->groupBy('r.featureArea')
            ->orderBy('requestCount', \SortDirection::Descending)
            ->getQuery()
            ->getArrayResult();

        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row['requestCount'];
        }

        $result = [];
        foreach ($rows as $row) {
            $area = $row['featureArea'];
            $featureArea = $area instanceof FeatureArea ? $area->value : $area;
            $count = (int) $row['requestCount'];
            $sharePercent = 0.0;
            if ($total > 0) {
                $sharePercent = round(((float) $count / (float) $total) * 100.0, 1);
            }
            $result[] = [
                'featureArea' => $featureArea,
                'requestCount' => $count,
                'sharePercent' => $sharePercent,
            ];
        }

        return $result;
    }

    /**
     * @return array{authenticated: int, anonymous: int}
     */
    public function authenticationSplitSince(\DateTimeImmutable $from): array
    {
        /** @var list<array{isAuthenticated: bool|int|string, requestCount: int|string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.isAuthenticated AS isAuthenticated', 'COUNT(r.id) AS requestCount')
            ->where('r.occurredAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->groupBy('r.isAuthenticated')
            ->getQuery()
            ->getArrayResult();

        $authenticated = 0;
        $anonymous = 0;
        foreach ($rows as $row) {
            $count = (int) $row['requestCount'];
            if ((bool) $row['isAuthenticated']) {
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
     * @return array{dau: int, wau: int, mau: int, sessionsLast7Days: int}
     */
    public function retentionSnapshot(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $dau = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT analytics_user_key) FROM analytics_request WHERE occurred_at >= :from AND analytics_user_key IS NOT NULL',
            ['from' => $this->startOfToday()->format('Y-m-d H:i:s')],
        );
        $wau = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT analytics_user_key) FROM analytics_request WHERE occurred_at >= :from AND analytics_user_key IS NOT NULL',
            ['from' => $this->daysAgo(6)->format('Y-m-d H:i:s')],
        );
        $mau = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT analytics_user_key) FROM analytics_request WHERE occurred_at >= :from AND analytics_user_key IS NOT NULL',
            ['from' => $this->daysAgo(29)->format('Y-m-d H:i:s')],
        );
        $sessions = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT session_key) FROM analytics_request WHERE occurred_at >= :from AND session_key IS NOT NULL',
            ['from' => $this->daysAgo(6)->format('Y-m-d H:i:s')],
        );

        return [
            'dau' => $dau,
            'wau' => $wau,
            'mau' => $mau,
            'sessionsLast7Days' => $sessions,
        ];
    }

    /**
     * @return list<array{featureArea: string, requestCount: int, avgDurationMs: float, p95DurationMs: float, avgQueries: float, errorRatePercent: float}>
     */
    public function performanceByAreaSince(\DateTimeImmutable $from): array
    {
        $conn = $this->getEntityManager()->getConnection();
        /** @var list<array{feature_area: string, request_count: int|string, avg_duration_ms: float|string, p95_duration_ms: float|string|null, avg_queries: float|string, error_rate: float|string}> $rows */
        $rows = $conn->fetchAllAssociative(
            <<<'SQL'
                SELECT feature_area,
                       COUNT(*) AS request_count,
                       AVG(duration_ms) AS avg_duration_ms,
                       PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms) AS p95_duration_ms,
                       AVG(db_query_count) AS avg_queries,
                       AVG(CASE WHEN http_status >= 400 THEN 1.0 ELSE 0.0 END) * 100 AS error_rate
                FROM analytics_request
                WHERE occurred_at >= :from
                GROUP BY feature_area
                ORDER BY avg_duration_ms DESC
            SQL,
            ['from' => $from->format('Y-m-d H:i:s')],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'featureArea' => $row['feature_area'],
                'requestCount' => (int) $row['request_count'],
                'avgDurationMs' => round((float) $row['avg_duration_ms'], 1),
                'p95DurationMs' => round((float) ($row['p95_duration_ms'] ?? 0), 1),
                'avgQueries' => round((float) $row['avg_queries'], 1),
                'errorRatePercent' => round((float) $row['error_rate'], 2),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{routeName: string, requestCount: int, avgDurationMs: float}>
     */
    public function slowestRoutesSince(\DateTimeImmutable $from, int $minCount = 5, int $limit = 10): array
    {
        $conn = $this->getEntityManager()->getConnection();
        /** @var list<array{route_name: string, request_count: int|string, avg_duration_ms: float|string}> $rows */
        $rows = $conn->fetchAllAssociative(
            <<<'SQL'
                SELECT route_name,
                       COUNT(*) AS request_count,
                       AVG(duration_ms) AS avg_duration_ms
                FROM analytics_request
                WHERE occurred_at >= :from
                  AND route_name IS NOT NULL
                GROUP BY route_name
                HAVING COUNT(*) >= :minCount
                ORDER BY avg_duration_ms DESC
                LIMIT :limit
            SQL,
            [
                'from' => $from->format('Y-m-d H:i:s'),
                'minCount' => $minCount,
                'limit' => $limit,
            ],
            [
                'from' => Types::STRING,
                'minCount' => Types::INTEGER,
                'limit' => Types::INTEGER,
            ],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'routeName' => $row['route_name'],
                'requestCount' => (int) $row['request_count'],
                'avgDurationMs' => round((float) $row['avg_duration_ms'], 1),
            ];
        }

        return $result;
    }

    /**
     * @param list<array{featureArea: string, requestCount: int, avgDurationMs: float, avgQueries: float}> $perf
     *
     * @return list<string>
     */
    public function buildPerformanceInsights(array $perf): array
    {
        if (\count($perf) < 2) {
            return [];
        }

        $byCount = $perf;
        usort($byCount, static fn (array $a, array $b): int => $b['requestCount'] <=> $a['requestCount']);
        $byLatency = $perf;
        usort($byLatency, static fn (array $a, array $b): int => $b['avgDurationMs'] <=> $a['avgDurationMs']);
        $byQueries = $perf;
        usort($byQueries, static fn (array $a, array $b): int => $b['avgQueries'] <=> $a['avgQueries']);

        $insights = [];
        $topThirdCount = array_slice(array_column($byCount, 'featureArea'), 0, max(1, (int) ceil(\count($perf) / 3)));
        $topThirdLatency = array_slice(array_column($byLatency, 'featureArea'), 0, max(1, (int) ceil(\count($perf) / 3)));
        $highUseSlow = array_values(array_intersect($topThirdCount, $topThirdLatency));
        foreach ($highUseSlow as $area) {
            $insights[] = sprintf(
                'High use + slow: "%s" is among the most used and slowest feature areas — prioritize optimization.',
                $area,
            );
        }

        $lowUse = array_slice(array_reverse(array_column($byCount, 'featureArea')), 0, max(1, (int) ceil(\count($perf) / 3)));
        $heavyDb = array_slice(array_column($byQueries, 'featureArea'), 0, max(1, (int) ceil(\count($perf) / 3)));
        foreach (array_values(array_intersect($lowUse, $heavyDb)) as $area) {
            $insights[] = sprintf(
                'Low use + heavy DB: "%s" is rarely used but query-heavy — consider simplifying or retiring.',
                $area,
            );
        }

        return \array_slice($insights, 0, 5);
    }

    public function startOfToday(): \DateTimeImmutable
    {
        return AnalyticsCalendar::startOfToday();
    }

    public function daysAgo(int $days): \DateTimeImmutable
    {
        return AnalyticsCalendar::daysAgo($days);
    }
}
