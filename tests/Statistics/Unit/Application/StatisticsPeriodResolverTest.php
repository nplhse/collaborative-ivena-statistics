<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsPeriodResolver;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame('all', StatisticsFilterPeriod::AllTime->value);
        // The v0.3 identifier of the whole history; old links are redirected until v1.0 (#1035).
        self::assertNull(StatisticsFilterPeriod::tryFrom('all_time'));
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

    /**
     * Month and year bounds are half-open: the upper bound is the first instant of the next month or year.
     */
    #[DataProvider('provideMonthAndYearBounds')]
    public function testResolvesMonthAndYearBoundsWithAnExclusiveEnd(
        StatisticsFilterPeriod $period,
        int $year,
        ?int $month,
        string $expectedFrom,
        string $expectedToExclusive,
    ): void {
        $bounds = StatisticsPeriodResolver::resolve(new StatisticsFilter(StatisticsFilterScope::Public, null, null, $period, $year, $month));

        self::assertSame($expectedFrom, $bounds->from?->format('Y-m-d H:i:s'));
        self::assertSame($expectedToExclusive, $bounds->toExclusive?->format('Y-m-d H:i:s'));
    }

    /**
     * @return iterable<string, array{StatisticsFilterPeriod, int, ?int, string, string}>
     */
    public static function provideMonthAndYearBounds(): iterable
    {
        yield 'January, 31 days' => [StatisticsFilterPeriod::Month, 2026, 1, '2026-01-01 00:00:00', '2026-02-01 00:00:00'];
        yield 'February in a leap year' => [StatisticsFilterPeriod::Month, 2028, 2, '2028-02-01 00:00:00', '2028-03-01 00:00:00'];
        yield 'December rolls into the next year' => [StatisticsFilterPeriod::Month, 2026, 12, '2026-12-01 00:00:00', '2027-01-01 00:00:00'];
        yield 'month 13 is clamped to December' => [StatisticsFilterPeriod::Month, 2026, 13, '2026-12-01 00:00:00', '2027-01-01 00:00:00'];
        yield 'month 0 is clamped to January' => [StatisticsFilterPeriod::Month, 2026, 0, '2026-01-01 00:00:00', '2026-02-01 00:00:00'];
        yield 'leap year' => [StatisticsFilterPeriod::Year, 2028, null, '2028-01-01 00:00:00', '2029-01-01 00:00:00'];
    }

    public function testMonthAndYearWithoutReferenceUseTheCurrentDate(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-11 21:40:00'));

        $month = StatisticsPeriodResolver::resolve($this->filterFor(StatisticsFilterPeriod::Month));
        $year = StatisticsPeriodResolver::resolve($this->filterFor(StatisticsFilterPeriod::Year));

        self::assertSame('2026-11-01 00:00:00', $month->from?->format('Y-m-d H:i:s'));
        self::assertSame('2026-12-01 00:00:00', $month->toExclusive?->format('Y-m-d H:i:s'));
        self::assertSame('2026-01-01 00:00:00', $year->from?->format('Y-m-d H:i:s'));
        self::assertSame('2027-01-01 00:00:00', $year->toExclusive?->format('Y-m-d H:i:s'));
    }

    private function filterFor(StatisticsFilterPeriod $period): StatisticsFilter
    {
        return new StatisticsFilter(StatisticsFilterScope::Public, null, null, $period);
    }
}
