<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Overview;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\Overview\OverviewComparisonKind;
use App\Statistics\Application\Overview\OverviewComparisonPeriod;
use App\Statistics\Application\Overview\OverviewComparisonPeriodResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * "Now" is 2026-11-13 10:00 in every case. Expected bounds are half-open [from, to).
 */
final class OverviewComparisonPeriodResolverTest extends TestCase
{
    use ClockSensitiveTrait;

    private const string NOW = '2026-11-13 10:00:00';

    /**
     * @return iterable<string, array{StatisticsFilterPeriod, ?int, ?int, ?int, string, string, string, string, OverviewComparisonKind}>
     */
    public static function periodProvider(): iterable
    {
        // Rolling window starts on the first day of the month 11 months ago and runs until now.
        // The comparison starts a year earlier and spans the same time: 2024-12-01 + (2025-12-01 → now).
        yield 'last 12 months' => [
            StatisticsFilterPeriod::Last12Months, null, null, null,
            '2025-12-01 00:00:00', self::NOW,
            '2024-12-01 00:00:00', '2025-11-13 10:00:00',
            OverviewComparisonKind::Last12Months,
        ];
        yield 'complete year' => [
            StatisticsFilterPeriod::Year, 2025, null, null,
            '2025-01-01 00:00:00', '2026-01-01 00:00:00',
            '2024-01-01 00:00:00', '2025-01-01 00:00:00',
            OverviewComparisonKind::Year,
        ];
        yield 'running year' => [
            StatisticsFilterPeriod::Year, 2026, null, null,
            '2026-01-01 00:00:00', self::NOW,
            '2025-01-01 00:00:00', '2025-11-13 10:00:00',
            OverviewComparisonKind::YearToDate,
        ];
        yield 'complete quarter' => [
            StatisticsFilterPeriod::Quarter, 2026, null, 3,
            '2026-07-01 00:00:00', '2026-10-01 00:00:00',
            '2026-04-01 00:00:00', '2026-07-01 00:00:00',
            OverviewComparisonKind::Quarter,
        ];
        yield 'running quarter' => [
            StatisticsFilterPeriod::Quarter, 2026, null, 4,
            '2026-10-01 00:00:00', self::NOW,
            '2026-07-01 00:00:00', '2026-08-13 10:00:00',
            OverviewComparisonKind::QuarterToDate,
        ];
        // A complete month is compared with the whole previous month, even if that is shorter.
        yield 'complete march against february' => [
            StatisticsFilterPeriod::Month, 2026, 3, null,
            '2026-03-01 00:00:00', '2026-04-01 00:00:00',
            '2026-02-01 00:00:00', '2026-03-01 00:00:00',
            OverviewComparisonKind::Month,
        ];
        yield 'running month' => [
            StatisticsFilterPeriod::Month, 2026, 11, null,
            '2026-11-01 00:00:00', self::NOW,
            '2026-10-01 00:00:00', '2026-10-13 10:00:00',
            OverviewComparisonKind::MonthToDate,
        ];
    }

    #[DataProvider('periodProvider')]
    public function testResolvesThePreviousPeriodOfTheSameLength(
        StatisticsFilterPeriod $period,
        ?int $year,
        ?int $month,
        ?int $quarter,
        string $primaryFrom,
        string $primaryTo,
        string $comparisonFrom,
        string $comparisonTo,
        OverviewComparisonKind $kind,
    ): void {
        $result = $this->resolver()->resolve($this->filter($period, $year, $month, $quarter));

        self::assertInstanceOf(OverviewComparisonPeriod::class, $result);
        self::assertSame($primaryFrom, $result->primary->from?->format('Y-m-d H:i:s'));
        self::assertSame($primaryTo, $result->primary->toExclusive?->format('Y-m-d H:i:s'));
        self::assertSame($comparisonFrom, $result->comparison->from?->format('Y-m-d H:i:s'));
        self::assertSame($comparisonTo, $result->comparison->toExclusive?->format('Y-m-d H:i:s'));
        self::assertSame($kind, $result->kind);
        self::assertSame('stats.overview.comparison.'.$kind->value, $result->labelKey());
    }

    public function testRunningMonthEndingAfterTheShorterPreviousMonthStopsAtTheStartOfThePrimaryPeriod(): void
    {
        // 31 March: 30 days and 10 hours of March so far, but February 2026 has only 28 days.
        $clock = self::mockTime(new \DateTimeImmutable('2026-03-31 10:00:00'));
        $result = new OverviewComparisonPeriodResolver($clock)->resolve($this->filter(StatisticsFilterPeriod::Month, 2026, 3, null));

        self::assertInstanceOf(OverviewComparisonPeriod::class, $result);
        self::assertSame('2026-02-01 00:00:00', $result->comparison->from?->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-01 00:00:00', $result->comparison->toExclusive?->format('Y-m-d H:i:s'));
    }

    public function testRunningQuarterAcrossTheDstChangeKeepsTheTimeOfDay(): void
    {
        // Summer time ends on 25 October 2026 in Europe/Berlin; the span 1 October → 13 November 10:00 is
        // 1 month, 12 days and 10 hours, so the comparison ends on 13 August at 10:00, not 11:00.
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            $clock = self::mockTime(new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Berlin')));
            $result = new OverviewComparisonPeriodResolver($clock)->resolve($this->filter(StatisticsFilterPeriod::Quarter, 2026, null, 4));

            self::assertInstanceOf(OverviewComparisonPeriod::class, $result);
            self::assertSame('2026-08-13 10:00:00', $result->comparison->toExclusive?->format('Y-m-d H:i:s'));
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function testAllTimeHasNoComparison(): void
    {
        self::assertNull($this->resolver()->resolve($this->filter(StatisticsFilterPeriod::AllTime, null, null, null)));
    }

    public function testFuturePeriodHasNoComparison(): void
    {
        self::assertNull($this->resolver()->resolve($this->filter(StatisticsFilterPeriod::Month, 2026, 12, null)));
    }

    private function resolver(): OverviewComparisonPeriodResolver
    {
        // Built in the default time zone, like the period bounds, whatever zone another test left behind.
        return new OverviewComparisonPeriodResolver(self::mockTime(new \DateTimeImmutable(self::NOW)));
    }

    private function filter(StatisticsFilterPeriod $period, ?int $year, ?int $month, ?int $quarter): StatisticsFilter
    {
        return new StatisticsFilter(StatisticsFilterScope::Hospital, 1, null, $period, $year, $month, $quarter);
    }
}
