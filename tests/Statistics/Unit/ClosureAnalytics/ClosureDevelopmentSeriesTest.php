<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;
use App\Statistics\ClosureAnalytics\Application\ClosureDevelopmentSeries;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureTimeBucket;
use PHPUnit\Framework\TestCase;

final class ClosureDevelopmentSeriesTest extends TestCase
{
    public function testRollingTwelveMonthsRunsThroughTheCurrentMonth(): void
    {
        $filled = ClosureDevelopmentSeries::complete(
            [new ClosureTimeBucket('2025-11', 0, 90, 90, 0, 1)],
            $this->criteria(new StatisticsPeriodBounds(new \DateTimeImmutable('2025-11-01 00:00:00'))),
            new \DateTimeImmutable('2026-10-02 11:00:00'),
        );

        $keys = array_map(static fn (ClosureTimeBucket $bucket): string => $bucket->key, $filled);
        self::assertSame('2025-11', $keys[0]);
        self::assertSame('2026-10', $keys[array_key_last($keys)]);
        self::assertCount(12, $filled);
        self::assertSame(90, $filled[0]->closedMinutes);
        self::assertSame(0, $filled[1]->closedMinutes);
        self::assertSame('2025-12', $filled[1]->key);
    }

    public function testLeavesBucketsUntouchedWhenTheWindowStartsAfterNow(): void
    {
        $buckets = [new ClosureTimeBucket('2027-01', 0, 30, 30, 0, 1)];
        $filled = ClosureDevelopmentSeries::complete(
            $buckets,
            $this->criteria(new StatisticsPeriodBounds(new \DateTimeImmutable('2027-01-01 00:00:00'))),
            new \DateTimeImmutable('2026-10-02 11:00:00'),
        );

        self::assertSame($buckets, $filled);
    }

    public function testClosedPeriodsAndFullHistoryStayUnchanged(): void
    {
        $buckets = [new ClosureTimeBucket('2026-01', 0, 60, 60, 0, 1)];
        $closed = ClosureDevelopmentSeries::complete(
            $buckets,
            $this->criteria(new StatisticsPeriodBounds(
                new \DateTimeImmutable('2026-01-01 00:00:00'),
                new \DateTimeImmutable('2026-03-01 00:00:00'),
            )),
            new \DateTimeImmutable('2026-10-02 11:00:00'),
        );
        $history = ClosureDevelopmentSeries::complete(
            $buckets,
            $this->criteria(new StatisticsPeriodBounds(null), TimeSeriesGrain::Month),
            new \DateTimeImmutable('2026-10-02 11:00:00'),
        );
        $daily = ClosureDevelopmentSeries::complete(
            $buckets,
            $this->criteria(new StatisticsPeriodBounds(new \DateTimeImmutable('2025-11-01 00:00:00')), TimeSeriesGrain::Day),
            new \DateTimeImmutable('2026-10-02 11:00:00'),
        );

        self::assertSame($buckets, $closed);
        self::assertSame($buckets, $history);
        self::assertSame($buckets, $daily);
    }

    private function criteria(StatisticsPeriodBounds $period, TimeSeriesGrain $grain = TimeSeriesGrain::Month): ClosureAnalyticsCriteria
    {
        return new ClosureAnalyticsCriteria(
            StatisticsScopeCriteria::public(),
            $period,
            $grain,
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All),
        );
    }
}
