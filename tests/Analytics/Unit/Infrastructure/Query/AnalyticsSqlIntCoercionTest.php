<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Unit\Infrastructure\Query;

use App\Analytics\Domain\AnalyticsCalendar;
use App\Analytics\Infrastructure\Query\AnalyticsDayAggregationQuery;
use App\Analytics\Infrastructure\Query\AnalyticsReportingQuery;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class AnalyticsSqlIntCoercionTest extends TestCase
{
    public function testDayAggregationQueryCoercesNumericStringsAndNonNumericCounts(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls('4', false);
        $query = new AnalyticsDayAggregationQuery($connection);
        $day = new \DateTimeImmutable('2026-05-10', AnalyticsCalendar::timezone());

        self::assertSame(4, $query->countRequests($day));
        self::assertSame(0, $query->countEvents($day));
    }

    public function testReportingQueryCoercesNumericStringsAndNonNumericCounts(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls('7', null);
        $query = new AnalyticsReportingQuery($connection);

        self::assertSame(7, $query->countSince(new \DateTimeImmutable('2026-05-01', AnalyticsCalendar::timezone())));
    }
}
