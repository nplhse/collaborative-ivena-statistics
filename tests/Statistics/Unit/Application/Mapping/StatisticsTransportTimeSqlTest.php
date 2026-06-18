<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Mapping;

use App\Statistics\Application\Mapping\StatisticsTransportTimeSql;
use PHPUnit\Framework\TestCase;

final class StatisticsTransportTimeSqlTest extends TestCase
{
    public function testPreciseMinutesExpressionWithoutAlias(): void
    {
        self::assertSame(
            'EXTRACT(EPOCH FROM (arrival_at - created_at)) / 60.0',
            StatisticsTransportTimeSql::preciseMinutesExpression(),
        );
    }

    public function testPreciseMinutesExpressionWithAlias(): void
    {
        self::assertSame(
            'EXTRACT(EPOCH FROM (asp.arrival_at - asp.created_at)) / 60.0',
            StatisticsTransportTimeSql::preciseMinutesExpression('asp'),
        );
    }

    public function testMedianAndMeanHelpersUsePreciseExpression(): void
    {
        self::assertStringContainsString(
            StatisticsTransportTimeSql::PRECISE_MINUTES_EXPRESSION,
            StatisticsTransportTimeSql::medianPreciseMinutes(),
        );
        self::assertStringContainsString(
            'EXTRACT(EPOCH FROM (asp.arrival_at - asp.created_at))',
            StatisticsTransportTimeSql::medianPreciseMinutes('asp'),
        );
        self::assertStringContainsString(
            'AVG(EXTRACT(EPOCH FROM (arrival_at - created_at))',
            StatisticsTransportTimeSql::meanPreciseMinutes(),
        );
    }
}
