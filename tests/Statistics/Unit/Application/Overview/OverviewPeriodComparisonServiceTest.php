<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Overview;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\Overview\OverviewPeriodComparisonService;
use App\Statistics\Application\StatisticsPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OverviewPeriodComparisonServiceTest extends TestCase
{
    #[DataProvider('relativePercentChangeProvider')]
    public function testRelativePercentChange(?float $current, ?float $previous, ?float $expected): void
    {
        self::assertSame($expected, OverviewPeriodComparisonService::relativePercentChange($current, $previous));
    }

    /**
     * @return iterable<string, array{?float, ?float, ?float}>
     */
    public static function relativePercentChangeProvider(): iterable
    {
        yield 'increase' => [120.0, 100.0, 20.0];
        yield 'decrease' => [80.0, 100.0, -20.0];
        yield 'from zero current' => [0.0, 0.0, 0.0];
        yield 'from zero previous' => [10.0, 0.0, null];
        yield 'null current' => [null, 10.0, null];
    }

    public function testBoundsDayCountUsesFallbackFromForAllTime(): void
    {
        $earliest = new \DateTimeImmutable('2024-01-01 00:00:00');

        $days = OverviewPeriodComparisonService::boundsDayCount(
            new StatisticsPeriodBounds(null),
            $earliest,
        );

        self::assertGreaterThan(365, $days);
    }

    public function testBoundsDayCountWithoutFallbackReturnsNullForUnboundedFrom(): void
    {
        self::assertNull(OverviewPeriodComparisonService::boundsDayCount(
            new StatisticsPeriodBounds(null),
        ));
    }

    public function testBoundsDayCountForRollingAllUsesOverviewStart(): void
    {
        $days = OverviewPeriodComparisonService::boundsDayCount(
            new StatisticsPeriodBounds(StatisticsPeriod::overviewPeriodStart()),
        );

        self::assertGreaterThan(300, $days);
        self::assertLessThanOrEqual(366, $days);
    }

    public function testBoundsDayCountForBoundedMonth(): void
    {
        $from = new \DateTimeImmutable('2025-03-01 00:00:00');
        $toExclusive = new \DateTimeImmutable('2025-04-01 00:00:00');

        self::assertSame(31, OverviewPeriodComparisonService::boundsDayCount(
            new StatisticsPeriodBounds($from, $toExclusive),
        ));
    }
}
