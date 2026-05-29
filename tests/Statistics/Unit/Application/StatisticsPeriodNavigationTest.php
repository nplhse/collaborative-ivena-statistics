<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Statistics\Application\Contract\ProjectionEarliestDateProviderInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsPeriodNavigation;
use PHPUnit\Framework\TestCase;

final class StatisticsPeriodNavigationTest extends TestCase
{
    private StatisticsPeriodNavigation $navigation;

    #[\Override]
    protected function setUp(): void
    {
        $earliestProvider = new readonly class implements ProjectionEarliestDateProviderInterface {
            public function getEarliestCreatedAt(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2020-03-15 10:00:00');
            }
        };
        $this->navigation = new StatisticsPeriodNavigation($earliestProvider);
    }

    public function testParentFromMonthGoesToQuarter(): void
    {
        $filter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::Month,
            2021,
            1,
        );

        $parent = $this->navigation->parent($filter);

        self::assertNotNull($parent);
        self::assertSame(StatisticsFilterPeriod::Quarter, $parent->period);
        self::assertSame(2021, $parent->referenceYear);
        self::assertSame(1, $parent->referenceQuarter);
    }

    public function testParentFromQuarterGoesToYear(): void
    {
        $filter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::Quarter,
            2021,
            null,
            2,
        );

        $parent = $this->navigation->parent($filter);

        self::assertNotNull($parent);
        self::assertSame(StatisticsFilterPeriod::Year, $parent->period);
        self::assertSame(2021, $parent->referenceYear);
    }

    public function testParentFromYearGoesToAllTime(): void
    {
        $filter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::Year,
            2021,
        );

        $parent = $this->navigation->parent($filter);

        self::assertNotNull($parent);
        self::assertSame(StatisticsFilterPeriod::AllTime, $parent->period);
    }

    public function testAllTimeHasNoParentOrStepNavigation(): void
    {
        $filter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::AllTime,
        );

        self::assertFalse($this->navigation->isParentEnabled($filter));
        self::assertFalse($this->navigation->isPreviousEnabled($filter));
        self::assertFalse($this->navigation->isNextEnabled($filter));
    }

    public function testMonthPreviousCrossesYearBoundary(): void
    {
        $filter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::Month,
            2021,
            1,
        );

        $previous = $this->navigation->previous($filter);

        self::assertNotNull($previous);
        self::assertSame(2020, $previous->referenceYear);
        self::assertSame(12, $previous->referenceMonth);
    }

    public function testQuarterPreviousCrossesYearBoundary(): void
    {
        $filter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::Quarter,
            2021,
            null,
            1,
        );

        $previous = $this->navigation->previous($filter);

        self::assertNotNull($previous);
        self::assertSame(2020, $previous->referenceYear);
        self::assertSame(4, $previous->referenceQuarter);
    }
}
