<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;
use App\Statistics\ClosureAnalytics\Application\ClosureDurationLoadWindow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use PHPUnit\Framework\TestCase;

final class ClosureDurationLoadWindowTest extends TestCase
{
    public function testCapsAnOpenPeriodAtTheBerlinWallClock(): void
    {
        $capped = ClosureDurationLoadWindow::cap(
            $this->criteria(null, null),
            new \DateTimeImmutable('2026-06-15 10:00:00', new \DateTimeZone('UTC')),
        );

        self::assertNull($capped->period->from);
        self::assertSame('2026-06-15 12:00:00', $capped->period->toExclusive?->format('Y-m-d H:i:s'));
    }

    public function testKeepsAnEarlierPeriodEnd(): void
    {
        $capped = ClosureDurationLoadWindow::cap(
            $this->criteria(new \DateTimeImmutable('2026-01-01 00:00:00'), new \DateTimeImmutable('2026-02-01 00:00:00')),
            new \DateTimeImmutable('2026-06-15 12:00:00', new \DateTimeZone('Europe/Berlin')),
        );

        self::assertSame('2026-01-01 00:00:00', $capped->period->from?->format('Y-m-d H:i:s'));
        self::assertSame('2026-02-01 00:00:00', $capped->period->toExclusive?->format('Y-m-d H:i:s'));
    }

    private function criteria(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): ClosureAnalyticsCriteria
    {
        return new ClosureAnalyticsCriteria(
            new StatisticsScopeCriteria([1]),
            new StatisticsPeriodBounds($from, $to),
            TimeSeriesGrain::Month,
            new StatisticsFilter(StatisticsFilterScope::Hospital, 1, null, StatisticsFilterPeriod::AllTime),
        );
    }
}
