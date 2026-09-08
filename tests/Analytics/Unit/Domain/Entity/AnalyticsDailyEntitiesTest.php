<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Unit\Domain\Entity;

use App\Analytics\Domain\Entity\AnalyticsAggregationRun;
use App\Analytics\Domain\Entity\AnalyticsEventDaily;
use App\Analytics\Domain\Entity\AnalyticsFilterAreaDaily;
use App\Analytics\Domain\Entity\AnalyticsFilterParamDaily;
use App\Analytics\Domain\Entity\AnalyticsRequestDaily;
use App\Analytics\Domain\Entity\AnalyticsSessionBoundaryDaily;
use App\Analytics\Domain\Entity\AnalyticsTransitionDaily;
use App\Analytics\Domain\Entity\AnalyticsUniquesDaily;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Domain\Enum\SessionBoundaryKind;
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
            errorCount: 1,
            sumDurationMs: 400,
            sumDbQueryCount: 12,
            sumDbTimeMs: 80,
        );

        self::assertNull($entity->getId());
        self::assertSame($date, $entity->getDate());
        self::assertSame(FeatureArea::Dashboard, $entity->getFeatureArea());
        self::assertSame('app_stats_dashboard', $entity->getRouteName());
        self::assertTrue($entity->isAuthenticated());
        self::assertSame('ROLE_PARTICIPANT', $entity->getUserRole());
        self::assertSame(4, $entity->getRequestCount());
        self::assertSame(1, $entity->getErrorCount());
        self::assertSame(400, $entity->getSumDurationMs());
        self::assertSame(12, $entity->getSumDbQueryCount());
        self::assertSame(80, $entity->getSumDbTimeMs());
    }

    public function testAggregationRunAndDailyRowsExposeConstructorValues(): void
    {
        $date = new \DateTimeImmutable('2026-05-11');
        $aggregatedAt = new \DateTimeImmutable('2026-05-12 02:15:00');

        $run = new AnalyticsAggregationRun($date, 9, 3, 5, $aggregatedAt);
        self::assertNull($run->getId());
        self::assertSame($date, $run->getDate());
        self::assertSame($aggregatedAt, $run->getAggregatedAt());
        self::assertSame(9, $run->getRawRequestCount());
        self::assertSame(3, $run->getRawEventCount());
        self::assertSame(5, $run->getAggregateRowsWritten());

        $event = new AnalyticsEventDaily($date, 'analysis_explorer.run', FeatureArea::Analysis, 'ROLE_ADMIN', 2);
        self::assertNull($event->getId());
        self::assertSame($date, $event->getDate());
        self::assertSame('analysis_explorer.run', $event->getEventName());
        self::assertSame(FeatureArea::Analysis, $event->getFeatureArea());
        self::assertSame('ROLE_ADMIN', $event->getUserRole());
        self::assertSame(2, $event->getEventCount());

        $filterArea = new AnalyticsFilterAreaDaily($date, FeatureArea::Explore, 3, 7);
        self::assertNull($filterArea->getId());
        self::assertSame($date, $filterArea->getDate());
        self::assertSame(FeatureArea::Explore, $filterArea->getFeatureArea());
        self::assertSame(3, $filterArea->getWithFilters());
        self::assertSame(7, $filterArea->getWithoutFilters());

        $filterParam = new AnalyticsFilterParamDaily($date, 'period', 6);
        self::assertNull($filterParam->getId());
        self::assertSame($date, $filterParam->getDate());
        self::assertSame('period', $filterParam->getParamName());
        self::assertSame(6, $filterParam->getUsageCount());

        $transition = new AnalyticsTransitionDaily($date, 'app_home', 'app_stats_dashboard', 4);
        self::assertNull($transition->getId());
        self::assertSame($date, $transition->getDate());
        self::assertSame('app_home', $transition->getFromRoute());
        self::assertSame('app_stats_dashboard', $transition->getToRoute());
        self::assertSame(4, $transition->getTransitionCount());

        $boundary = new AnalyticsSessionBoundaryDaily($date, 'app_home', SessionBoundaryKind::Exit, 2);
        self::assertNull($boundary->getId());
        self::assertSame($date, $boundary->getDate());
        self::assertSame('app_home', $boundary->getRouteName());
        self::assertSame(SessionBoundaryKind::Exit, $boundary->getKind());
        self::assertSame(2, $boundary->getSessionCount());

        $uniques = new AnalyticsUniquesDaily($date, 8, 11, 13);
        self::assertNull($uniques->getId());
        self::assertSame($date, $uniques->getDate());
        self::assertSame(8, $uniques->getDau());
        self::assertSame(11, $uniques->getDistinctVisitors());
        self::assertSame(13, $uniques->getDistinctSessions());
    }
}
