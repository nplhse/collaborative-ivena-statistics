<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\TimeSeries;

use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;
use App\Statistics\Application\TimeSeries\TimeSeriesGrainResolver;
use PHPUnit\Framework\TestCase;

final class TimeSeriesGrainResolverTest extends TestCase
{
    public function testMapsPeriodToAutomaticGrain(): void
    {
        self::assertSame(TimeSeriesGrain::Day, TimeSeriesGrainResolver::resolve(StatisticsFilterPeriod::Month));
        self::assertSame(TimeSeriesGrain::Month, TimeSeriesGrainResolver::resolve(StatisticsFilterPeriod::Quarter));
        self::assertSame(TimeSeriesGrain::Month, TimeSeriesGrainResolver::resolve(StatisticsFilterPeriod::Year));
        self::assertSame(TimeSeriesGrain::Month, TimeSeriesGrainResolver::resolve(StatisticsFilterPeriod::All));
        self::assertSame(TimeSeriesGrain::Month, TimeSeriesGrainResolver::resolve(StatisticsFilterPeriod::AllTime));
    }

    public function testClampsCoarseTimeAxisGrainForClosedPeriods(): void
    {
        self::assertSame('day', TimeSeriesGrainResolver::clampGrainValue(StatisticsFilterPeriod::Month, 'month'));
        self::assertSame('day', TimeSeriesGrainResolver::clampGrainValue(StatisticsFilterPeriod::Month, 'quarter'));
        self::assertSame('day', TimeSeriesGrainResolver::clampGrainValue(StatisticsFilterPeriod::Month, 'year'));
        self::assertSame('week', TimeSeriesGrainResolver::clampGrainValue(StatisticsFilterPeriod::Month, 'week'));

        self::assertSame('month', TimeSeriesGrainResolver::clampGrainValue(StatisticsFilterPeriod::Quarter, 'quarter'));
        self::assertSame('month', TimeSeriesGrainResolver::clampGrainValue(StatisticsFilterPeriod::Quarter, 'year'));
        self::assertSame('week', TimeSeriesGrainResolver::clampGrainValue(StatisticsFilterPeriod::Quarter, 'week'));
        self::assertSame('month', TimeSeriesGrainResolver::clampGrainValue(StatisticsFilterPeriod::Quarter, 'month'));

        self::assertSame('month', TimeSeriesGrainResolver::clampGrainValue(StatisticsFilterPeriod::Year, 'year'));
        self::assertSame('week', TimeSeriesGrainResolver::clampGrainValue(StatisticsFilterPeriod::Year, 'week'));
        self::assertSame('month', TimeSeriesGrainResolver::clampGrainValue(StatisticsFilterPeriod::Year, 'month'));
    }

    public function testDoesNotClampOpenPeriods(): void
    {
        self::assertSame('year', TimeSeriesGrainResolver::clampGrainValue(StatisticsFilterPeriod::AllTime, 'year'));
        self::assertSame('month', TimeSeriesGrainResolver::clampGrainValue(StatisticsFilterPeriod::All, 'month'));
        self::assertSame('year', TimeSeriesGrainResolver::clampGrainValue(StatisticsFilterPeriod::All, 'year'));
    }
}
