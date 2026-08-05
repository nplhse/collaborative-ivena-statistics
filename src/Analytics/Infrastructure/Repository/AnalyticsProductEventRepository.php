<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Repository;

use App\Analytics\Application\Engagement\EngagementDepthResolver;
use App\Analytics\Domain\Entity\AnalyticsProductEvent;
use App\Analytics\Domain\UsageEventName;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsProductEvent>
 */
final class AnalyticsProductEventRepository extends ServiceEntityRepository
{
    private const string TIMEZONE = 'Europe/Berlin';

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
     * @param list<string> $steps
     *
     * @return list<array{step: string, uniqueUsers: int, conversionFromPreviousPercent: float|null}>
     */
    public function onboardingFunnelSince(\DateTimeImmutable $from, array $steps): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $previous = null;
        $result = [];

        foreach ($steps as $step) {
            $count = (int) $conn->fetchOne(
                <<<'SQL'
                    SELECT COUNT(DISTINCT analytics_user_key)
                    FROM analytics_product_event
                    WHERE occurred_at >= :from
                      AND event_name = :event
                      AND analytics_user_key IS NOT NULL
                SQL,
                [
                    'from' => $from->format('Y-m-d H:i:s'),
                    'event' => $step,
                ],
            );

            $conversion = null;
            if (null !== $previous && $previous > 0) {
                $conversion = round(((float) $count / (float) $previous) * 100.0, 1);
            }

            $result[] = [
                'step' => $step,
                'uniqueUsers' => $count,
                'conversionFromPreviousPercent' => $conversion,
            ];
            $previous = $count;
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

    /**
     * @return list<array{metric: string, medianDays: float|null, sampleSize: int}>
     */
    public function timeToFirstMetrics(\DateTimeImmutable $from): array
    {
        $targets = [
            'import' => UsageEventName::IMPORT_COMPLETED,
            'analysis' => UsageEventName::ANALYSIS_EXPLORER_RUN,
            'export' => UsageEventName::ANALYSIS_EXPLORER_EXPORTED_CSV,
        ];

        $startEvents = [
            UsageEventName::USER_BECAME_PARTICIPANT,
            UsageEventName::USER_REGISTERED,
        ];

        $result = [];
        foreach ($targets as $label => $targetEvent) {
            $days = $this->medianDaysBetweenFirstEvents($from, $startEvents, $targetEvent);
            $result[] = [
                'metric' => $label,
                'medianDays' => $days['median'],
                'sampleSize' => $days['sampleSize'],
            ];
        }

        return $result;
    }

    /**
     * @param list<string> $startEvents
     *
     * @return array{median: float|null, sampleSize: int}
     */
    private function medianDaysBetweenFirstEvents(
        \DateTimeImmutable $from,
        array $startEvents,
        string $targetEvent,
    ): array {
        $conn = $this->getEntityManager()->getConnection();
        $startPlaceholders = [];
        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'target' => $targetEvent,
        ];
        foreach ($startEvents as $i => $event) {
            $key = 'start_'.$i;
            $startPlaceholders[] = ':'.$key;
            $params[$key] = $event;
        }
        $inList = implode(', ', $startPlaceholders);

        /** @var list<array{days: float|string}> $rows */
        $rows = $conn->fetchAllAssociative(
            <<<SQL
                WITH starts AS (
                    SELECT analytics_user_key, MIN(occurred_at) AS started_at
                    FROM analytics_product_event
                    WHERE occurred_at >= :from
                      AND analytics_user_key IS NOT NULL
                      AND event_name IN ($inList)
                    GROUP BY analytics_user_key
                ),
                targets AS (
                    SELECT analytics_user_key, MIN(occurred_at) AS reached_at
                    FROM analytics_product_event
                    WHERE occurred_at >= :from
                      AND analytics_user_key IS NOT NULL
                      AND event_name = :target
                    GROUP BY analytics_user_key
                )
                SELECT EXTRACT(EPOCH FROM (t.reached_at - s.started_at)) / 86400.0 AS days
                FROM starts s
                INNER JOIN targets t ON t.analytics_user_key = s.analytics_user_key
                WHERE t.reached_at >= s.started_at
                ORDER BY days
            SQL,
            $params,
        );

        $values = array_map(static fn (array $row): float => (float) $row['days'], $rows);
        $sampleSize = \count($values);
        if (0 === $sampleSize) {
            return ['median' => null, 'sampleSize' => 0];
        }

        $mid = intdiv($sampleSize, 2);
        if (0 === $sampleSize % 2) {
            $median = ($values[$mid - 1] + $values[$mid]) / 2.0;
        } else {
            $median = $values[$mid];
        }

        return ['median' => round($median, 1), 'sampleSize' => $sampleSize];
    }

    public function daysAgo(int $days): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone(self::TIMEZONE))
            ->modify(sprintf('-%d days', $days));
    }
}
