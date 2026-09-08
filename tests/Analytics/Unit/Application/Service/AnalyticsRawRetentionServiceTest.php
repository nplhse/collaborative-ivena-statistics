<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Unit\Application\Service;

use App\Analytics\Application\Service\AnalyticsRawRetentionService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class AnalyticsRawRetentionServiceTest extends TestCase
{
    public function testRetentionDaysIsAtLeastOne(): void
    {
        $service = new AnalyticsRawRetentionService($this->createStub(Connection::class), 0);

        self::assertSame(1, $service->retentionDays());
    }

    public function testPurgeReturnsEmptyResultWhenNoEligibleDatesExist(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([]);
        $service = new AnalyticsRawRetentionService($connection, 30);

        $result = $service->purgeExpiredRaw();

        self::assertSame([], $result->datesCleaned);
        self::assertSame(0, $result->requestsDeleted);
        self::assertSame(0, $result->eventsDeleted);
        self::assertSame(0, $result->rawRowsDeleted());
    }

    public function testDryRunCoercesNumericStringsAndIgnoresNonNumericCounts(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn(['2026-01-01']);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls('2', 'not-a-number');
        $service = new AnalyticsRawRetentionService($connection, 30);

        $result = $service->purgeExpiredRaw(true);

        self::assertSame(['2026-01-01'], $result->datesCleaned);
        self::assertSame(2, $result->requestsDeleted);
        self::assertSame(0, $result->eventsDeleted);
    }
}
