<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventType;
use PHPUnit\Framework\TestCase;

final class ClosureAnalyticsCriteriaTest extends TestCase
{
    public function testWithPeriodKeepsEventTypeFilters(): void
    {
        $criteria = new ClosureAnalyticsCriteria(
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            TimeSeriesGrain::Month,
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::AllTime),
            eventTypes: [ClosureEventType::Cluster->value],
            hospitalIds: [18],
        );

        $windowed = $criteria->withPeriod(new StatisticsPeriodBounds(
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-04-01'),
        ));

        self::assertSame(['cluster'], $windowed->eventTypes);
        self::assertSame([18], $windowed->hospitalIds);
        self::assertSame('2026-03-01 00:00:00', $windowed->period->from?->format('Y-m-d H:i:s'));
    }

    public function testWithSameDayDepartmentsKeepsScopeAndClearsOtherFilters(): void
    {
        $criteria = new ClosureAnalyticsCriteria(
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            TimeSeriesGrain::Month,
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::AllTime),
            specialityIds: [9],
            careLevels: ['emergency'],
            eventTypes: [ClosureEventType::Cluster->value],
            hospitalIds: [18],
        );

        $windowed = $criteria->withSameDayDepartments(
            new StatisticsPeriodBounds(
                new \DateTimeImmutable('2026-05-01'),
                new \DateTimeImmutable('2026-05-02'),
            ),
            [4, 5],
            [18],
        );

        self::assertSame([4, 5], $windowed->departmentIds);
        self::assertSame([18], $windowed->hospitalIds);
        self::assertSame([], $windowed->specialityIds);
        self::assertSame([], $windowed->careLevels);
        self::assertSame([], $windowed->eventTypes);
        self::assertSame('2026-05-01 00:00:00', $windowed->period->from?->format('Y-m-d H:i:s'));
        self::assertTrue($criteria->scope === $windowed->scope);
    }
}
