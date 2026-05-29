<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsPeriodResolver;
use PHPUnit\Framework\TestCase;

final class StatisticsPeriodResolverTest extends TestCase
{
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
}
