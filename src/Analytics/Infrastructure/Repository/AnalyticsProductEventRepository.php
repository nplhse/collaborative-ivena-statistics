<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Repository;

use App\Analytics\Application\Engagement\EngagementDepthResolver;
use App\Analytics\Domain\AnalyticsCalendar;
use App\Analytics\Domain\Entity\AnalyticsProductEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsProductEvent>
 */
final class AnalyticsProductEventRepository extends ServiceEntityRepository
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        ManagerRegistry $registry,
        private readonly EngagementDepthResolver $engagementDepthResolver,
    ) {
        parent::__construct($registry, AnalyticsProductEvent::class);
    }

    public function save(AnalyticsProductEvent $event, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($event);
        if ($flush) {
            $em->flush();
        }
    }

    /**
     * @return list<array{eventName: string, eventCount: int, uniqueUsers: int}>
     */
    public function topEventsSince(\DateTimeImmutable $from, int $limit = 15): array
    {
        $conn = $this->getEntityManager()->getConnection();
        /** @var list<array{event_name: string, event_count: int|string, unique_users: int|string}> $rows */
        $rows = $conn->fetchAllAssociative(
            <<<'SQL'
                SELECT event_name,
                       COUNT(*) AS event_count,
                       COUNT(DISTINCT analytics_user_key) AS unique_users
                FROM analytics_product_event
                WHERE occurred_at >= :from
                GROUP BY event_name
                ORDER BY event_count DESC
                LIMIT :limit
            SQL,
            [
                'from' => $from->format('Y-m-d H:i:s'),
                'limit' => $limit,
            ],
            [
                'from' => Types::STRING,
                'limit' => Types::INTEGER,
            ],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'eventName' => $row['event_name'],
                'eventCount' => (int) $row['event_count'],
                'uniqueUsers' => (int) $row['unique_users'],
            ];
        }

        return $result;
    }

    /**
     * @return list<array{eventName: string, userRole: string, eventCount: int}>
     */
    public function eventsByRoleSince(\DateTimeImmutable $from): array
    {
        $conn = $this->getEntityManager()->getConnection();
        /** @var list<array{event_name: string, user_role: string|null, event_count: int|string}> $rows */
        $rows = $conn->fetchAllAssociative(
            <<<'SQL'
                SELECT event_name,
                       COALESCE(context->>'user_role', 'unknown') AS user_role,
                       COUNT(*) AS event_count
                FROM analytics_product_event
                WHERE occurred_at >= :from
                GROUP BY event_name, COALESCE(context->>'user_role', 'unknown')
                ORDER BY event_count DESC
                LIMIT 40
            SQL,
            ['from' => $from->format('Y-m-d H:i:s')],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'eventName' => $row['event_name'],
                'userRole' => $row['user_role'] ?? 'unknown',
                'eventCount' => (int) $row['event_count'],
            ];
        }

        return $result;
    }

    /**
     * @return array<string, int> analytics_user_key => max event level
     */
    public function maxEventLevelsByUserSince(\DateTimeImmutable $from): array
    {
        $conn = $this->getEntityManager()->getConnection();
        /** @var list<array{analytics_user_key: string, event_name: string}> $rows */
        $rows = $conn->fetchAllAssociative(
            <<<'SQL'
                SELECT DISTINCT analytics_user_key, event_name
                FROM analytics_product_event
                WHERE occurred_at >= :from
                  AND analytics_user_key IS NOT NULL
            SQL,
            ['from' => $from->format('Y-m-d H:i:s')],
        );

        $levels = [];
        foreach ($rows as $row) {
            $key = $row['analytics_user_key'];
            $level = $this->engagementDepthResolver->levelForEventName($row['event_name']);
            $levels[$key] = max($levels[$key] ?? 0, $level);
        }

        return $levels;
    }

    public function daysAgo(int $days): \DateTimeImmutable
    {
        return AnalyticsCalendar::daysAgo($days);
    }
}
