<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Health;

use App\Shared\Application\Health\HealthCheckService;
use App\Shared\Application\Health\HealthCheckStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;

final class HealthCheckServiceTest extends TestCase
{
    private const string APP_VERSION = 'v0.0.4-alpha';

    public function testReturnsHealthyWhenDatabaseOkAndNoFailedMessages(): void
    {
        $connection = $this->createConnectionMock(failedCount: 0);

        $service = new HealthCheckService($connection, self::APP_VERSION);
        $report = $service->check();

        self::assertSame(HealthCheckStatus::Healthy, $report->status);
        self::assertSame(self::APP_VERSION, $report->version);
        self::assertSame('ok', $report->checks['database']);
        self::assertSame('ok', $report->checks['messenger_failed']);
        self::assertSame(200, $report->httpStatusCode());
    }

    public function testReturnsDegradedWhenFailedMessagesExist(): void
    {
        $connection = $this->createConnectionMock(failedCount: 3);

        $service = new HealthCheckService($connection, self::APP_VERSION);
        $report = $service->check();

        self::assertSame(HealthCheckStatus::Degraded, $report->status);
        self::assertSame('ok', $report->checks['database']);
        self::assertSame('3 failed message(s)', $report->checks['messenger_failed']);
        self::assertSame(200, $report->httpStatusCode());
    }

    public function testReturnsUnhealthyWhenDatabaseUnreachable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willThrowException($this->createMock(Exception::class));

        $service = new HealthCheckService($connection, self::APP_VERSION);
        $report = $service->check();

        self::assertSame(HealthCheckStatus::Unhealthy, $report->status);
        self::assertSame('unreachable', $report->checks['database']);
        self::assertSame('skipped', $report->checks['messenger_failed']);
        self::assertSame(503, $report->httpStatusCode());
    }

    private function createConnectionMock(int $failedCount): Connection
    {
        $failedResult = $this->createMock(Result::class);
        $failedResult->method('fetchOne')->willReturn($failedCount);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(static function (string $sql) use ($failedResult): \PHPUnit\Framework\MockObject\MockObject {
                if ('SELECT 1' === $sql) {
                    return $failedResult;
                }

                if (str_contains($sql, 'messenger_messages')) {
                    return $failedResult;
                }

                self::fail('Unexpected SQL: '.$sql);
            });

        return $connection;
    }
}
