<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Overview;

use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\Overview\OverviewSelfBenchmarkFactory;
use PHPUnit\Framework\TestCase;

final class OverviewSelfBenchmarkFactoryTest extends TestCase
{
    public function testUsesRollingAllWhenPrimaryIsBounded(): void
    {
        self::assertSame(
            StatisticsFilterPeriod::All,
            OverviewSelfBenchmarkFactory::baselinePeriodFor(StatisticsFilterPeriod::Month),
        );
    }

    public function testUsesAllTimeWhenPrimaryIsRollingAll(): void
    {
        self::assertSame(
            StatisticsFilterPeriod::AllTime,
            OverviewSelfBenchmarkFactory::baselinePeriodFor(StatisticsFilterPeriod::All),
        );
    }
}
