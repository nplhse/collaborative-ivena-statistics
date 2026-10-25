<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Health;

use App\Shared\Application\Health\HealthCheckContributorInterface;
use App\Shared\Application\Health\HealthCheckResult;
use App\Shared\Application\Health\HealthCheckService;
use App\Shared\Application\Health\HealthCheckStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame(
            [
                'status' => 'healthy',
                'checks' => ['database' => 'ok'],
            ],
            $report->toPublicArray(),
        );
        self::assertArrayHasKey('version', $report->toArray());
        self::assertArrayHasKey('messenger_failed', $report->toArray()['checks']);
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
        self::assertSame(
            [
                'status' => 'degraded',
                'checks' => ['database' => 'ok'],
            ],
            $report->toPublicArray(),
        );
    }

    public function testReturnsUnhealthyWhenDatabaseUnreachable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willThrowException($this->createStub(Exception::class));

        $service = new HealthCheckService($connection, self::APP_VERSION);
        $report = $service->check();

        self::assertSame(HealthCheckStatus::Unhealthy, $report->status);
        self::assertSame('unreachable', $report->checks['database']);
        self::assertSame('skipped', $report->checks['messenger_failed']);
        self::assertSame(503, $report->httpStatusCode());
        self::assertSame(
            [
                'status' => 'unhealthy',
                'checks' => ['database' => 'unreachable'],
            ],
            $report->toPublicArray(),
        );
    }

    /**
     * @return iterable<string, array{int, list<HealthCheckStatus>, HealthCheckStatus}>
     */
    public static function aggregations(): iterable
    {
        yield 'all healthy' => [0, [HealthCheckStatus::Healthy, HealthCheckStatus::Healthy], HealthCheckStatus::Healthy];
        yield 'degraded contributor' => [0, [HealthCheckStatus::Healthy, HealthCheckStatus::Degraded], HealthCheckStatus::Degraded];
        yield 'unhealthy contributor' => [0, [HealthCheckStatus::Unhealthy, HealthCheckStatus::Degraded], HealthCheckStatus::Unhealthy];
        yield 'failed messages and healthy contributor' => [2, [HealthCheckStatus::Healthy], HealthCheckStatus::Degraded];
        yield 'failed messages and unhealthy contributor' => [2, [HealthCheckStatus::Unhealthy], HealthCheckStatus::Unhealthy];
        yield 'no contributors' => [0, [], HealthCheckStatus::Healthy];
    }

    /**
     * @param list<HealthCheckStatus> $contributed
     */
    #[DataProvider('aggregations')]
    public function testOverallStatusIsTheWorstOfAllChecks(int $failedCount, array $contributed, HealthCheckStatus $expected): void
    {
        $contributors = [];
        foreach ($contributed as $index => $status) {
            $contributors[] = $this->contributor(new HealthCheckResult('check_'.$index, $status, $status->value));
        }

        $report = new HealthCheckService($this->createConnectionMock($failedCount), self::APP_VERSION, $contributors)->check();

        self::assertSame($expected, $report->status);
        self::assertSame(HealthCheckStatus::Unhealthy === $expected ? 503 : 200, $report->httpStatusCode());
    }

    public function testContributedChecksFollowTheBuiltInChecksAndOnlyPublicOnesArePublic(): void
    {
        $service = new HealthCheckService($this->createConnectionMock(failedCount: 0), self::APP_VERSION, [
            $this->contributor(new HealthCheckResult('storage', HealthCheckStatus::Degraded, 'stale', public: true)),
            $this->contributor(new HealthCheckResult('internal', HealthCheckStatus::Healthy, '42 items')),
        ]);

        $report = $service->check();

        self::assertSame(
            ['database' => 'ok', 'messenger_failed' => 'ok', 'storage' => 'stale', 'internal' => '42 items'],
            $report->checks,
        );
        self::assertSame(
            ['status' => 'degraded', 'checks' => ['database' => 'ok', 'storage' => 'stale']],
            $report->toPublicArray(),
        );
    }

    public function testContributorsDoNotRunWhenTheDatabaseIsUnreachable(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException($this->createStub(Exception::class));

        $contributor = $this->createMock(HealthCheckContributorInterface::class);
        $contributor->expects(self::never())->method('check');

        $report = new HealthCheckService($connection, self::APP_VERSION, [$contributor])->check();

        self::assertSame(HealthCheckStatus::Unhealthy, $report->status);
        self::assertSame(['database' => 'unreachable', 'messenger_failed' => 'skipped'], $report->checks);
    }

    private function contributor(HealthCheckResult $result): HealthCheckContributorInterface
    {
        $contributor = $this->createMock(HealthCheckContributorInterface::class);
        $contributor->expects(self::once())->method('check')->willReturn($result);

        return $contributor;
    }

    private function createConnectionMock(int $failedCount): Connection
    {
        $failedResult = $this->createStub(Result::class);
        $failedResult->method('fetchOne')->willReturn($failedCount);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(static function (string $sql) use ($failedResult): Result {
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
