<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsPeriodResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class StatisticsPeriodResolverTest extends TestCase
{
    use ClockSensitiveTrait;

    /**
     * 2026-11-11: the rolling window starts at the first day of the month eleven months earlier
     * (2025-12-01), so it covers Dec 2025 … Nov 2026 = 12 calendar months, with no upper bound.
     */
    public function testLast12MonthsStartsElevenMonthsBeforeTheCurrentMonth(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-11 21:40:00'));

        $bounds = StatisticsPeriodResolver::resolve($this->filterFor(StatisticsFilterPeriod::Last12Months));

        self::assertSame('2025-12-01 00:00:00', $bounds->from?->format('Y-m-d H:i:s'));
        self::assertNull($bounds->toExclusive);
    }

    public function testLast12MonthsOnTheFirstDayOfAMonthStillCoversTwelveMonths(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-01-01 00:00:00'));

        $bounds = StatisticsPeriodResolver::resolve($this->filterFor(StatisticsFilterPeriod::Last12Months));

        self::assertSame('2025-02-01 00:00:00', $bounds->from?->format('Y-m-d H:i:s'));
        self::assertNull($bounds->toExclusive);
    }

    public function testAllTimeHasNeitherLowerNorUpperBound(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-11 21:40:00'));

        $bounds = StatisticsPeriodResolver::resolve($this->filterFor(StatisticsFilterPeriod::AllTime));

        self::assertNull($bounds->from);
        self::assertNull($bounds->toExclusive);
    }

    public function testPeriodIdentifiers(): void
    {
        self::assertSame('last_12_months', StatisticsFilterPeriod::Last12Months->value);
        self::assertSame('all_time', StatisticsFilterPeriod::AllTime->value);
        // Reserved for the whole history once the #1017 transition redirect is removed (v0.4-beta).
        self::assertNull(StatisticsFilterPeriod::tryFrom('all'));
    }

    public function testResolvesQuarterBounds(): void
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

        $bounds = StatisticsPeriodResolver::resolve($filter);

        self::assertSame('2021-04-01 00:00:00', $bounds->from?->format('Y-m-d H:i:s'));
        self::assertSame('2021-07-01 00:00:00', $bounds->toExclusive?->format('Y-m-d H:i:s'));
    }

    public function testClampsInvalidQuarterToValidRange(): void
    {
        $filter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::Quarter,
            2021,
            null,
            9,
        );

        $bounds = StatisticsPeriodResolver::resolve($filter);

        self::assertSame('2021-10-01 00:00:00', $bounds->from?->format('Y-m-d H:i:s'));
    }

    private function filterFor(StatisticsFilterPeriod $period): StatisticsFilter
    {
        return new StatisticsFilter(StatisticsFilterScope::Public, null, null, $period);
    }
}
