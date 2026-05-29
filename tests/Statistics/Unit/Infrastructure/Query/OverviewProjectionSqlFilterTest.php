<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Infrastructure\Query;

use App\Statistics\Infrastructure\Query\Overview\OverviewProjectionSqlFilter;
use App\Statistics\Infrastructure\Query\Overview\OverviewQueryCriteria;
use PHPUnit\Framework\TestCase;

final class OverviewProjectionSqlFilterTest extends TestCase
{
    public function testOmitsCreatedAtWhenFromIsNull(): void
    {
        [$where, $params] = OverviewProjectionSqlFilter::buildWhereClause(
            new OverviewQueryCriteria(null, null, null),
        );

        self::assertStringNotContainsString('created_at', $where);
        self::assertArrayNotHasKey('from', $params);
    }

    public function testAddsCreatedAtWhenFromIsSet(): void
    {
        $from = new \DateTimeImmutable('2025-01-01 00:00:00');
        [$where, $params] = OverviewProjectionSqlFilter::buildWhereClause(
            new OverviewQueryCriteria($from, null, null),
        );

        self::assertStringContainsString('created_at >= :from', $where);
        self::assertSame('2025-01-01 00:00:00', $params['from']);
        self::assertStringNotContainsString('1970', $params['from']);
    }

    public function testAddsUpperBoundForQuarterPeriod(): void
    {
        $from = new \DateTimeImmutable('2021-04-01 00:00:00');
        $toExclusive = new \DateTimeImmutable('2021-07-01 00:00:00');
        [$where, $params] = OverviewProjectionSqlFilter::buildWhereClause(
            new OverviewQueryCriteria($from, $toExclusive, null),
        );

        self::assertStringContainsString('created_at >= :from', $where);
        self::assertStringContainsString('created_at < :to_exclusive', $where);
        self::assertSame('2021-04-01 00:00:00', $params['from']);
        self::assertSame('2021-07-01 00:00:00', $params['to_exclusive']);
    }
}
