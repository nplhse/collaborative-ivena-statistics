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

    public function startOfToday(): \DateTimeImmutable
    {
        return AnalyticsCalendar::startOfToday();
    }

    public function daysAgo(int $days): \DateTimeImmutable
    {
        return AnalyticsCalendar::daysAgo($days);
    }
}
