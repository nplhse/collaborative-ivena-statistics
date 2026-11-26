<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Unit\Domain\Entity;

use App\Analytics\Domain\Entity\AnalyticsAccessDaily;
use App\Analytics\Domain\Entity\AnalyticsAggregationRun;
use App\Analytics\Domain\Entity\AnalyticsRequestDaily;
use App\Analytics\Domain\Entity\AnalyticsUniquesDaily;
use App\Analytics\Domain\Enum\FeatureArea;
use PHPUnit\Framework\TestCase;

final class AnalyticsDailyEntitiesTest extends TestCase
{
    public function testRequestDailyExposesConstructorValues(): void
    {
        $date = new \DateTimeImmutable('2026-05-10');
        $entity = new AnalyticsRequestDaily(
            date: $date,
            featureArea: FeatureArea::Dashboard,
            routeName: 'app_stats_dashboard',
            isAuthenticated: true,
            userRole: 'ROLE_PARTICIPANT',
            requestCount: 4,
        );

        self::assertNull($entity->getId());
        self::assertSame($date, $entity->getDate());
        self::assertSame(FeatureArea::Dashboard, $entity->getFeatureArea());
        self::assertSame('app_stats_dashboard', $entity->getRouteName());
        self::assertTrue($entity->isAuthenticated());
        self::assertSame('ROLE_PARTICIPANT', $entity->getUserRole());
        self::assertSame(4, $entity->getRequestCount());
    }

    public function testAggregationRunAndDailyRowsExposeConstructorValues(): void
    {
        $date = new \DateTimeImmutable('2026-05-11');
        $aggregatedAt = new \DateTimeImmutable('2026-05-12 02:15:00');

        $run = new AnalyticsAggregationRun($date, 9, 5, $aggregatedAt);
        self::assertNull($run->getId());
        self::assertSame($date, $run->getDate());
        self::assertSame($aggregatedAt, $run->getAggregatedAt());
        self::assertSame(9, $run->getRawRequestCount());
        self::assertSame(5, $run->getAggregateRowsWritten());

        $uniques = new AnalyticsUniquesDaily($date, 8, 11, 13);
        self::assertNull($uniques->getId());
        self::assertSame($date, $uniques->getDate());
        self::assertSame(8, $uniques->getDau());
        self::assertSame(11, $uniques->getDistinctVisitors());
        self::assertSame(13, $uniques->getDistinctSessions());
    }

    public function testAccessDailyExposesConstructorValues(): void
    {
        $date = new \DateTimeImmutable('2026-10-31');
        $entity = new AnalyticsAccessDaily($date, false, 7);

        self::assertNull($entity->getId());
        self::assertSame($date, $entity->getDate());
        self::assertFalse($entity->isAuthenticated());
        self::assertSame(7, $entity->getAccessCount());
    }
}
