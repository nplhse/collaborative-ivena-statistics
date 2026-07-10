<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Application\PublicId;

use App\Shared\Application\PublicId\PublicIdBackfillInterruptedException;
use App\Shared\Application\PublicId\PublicIdBackfillRunControl;
use App\Shared\Application\PublicId\PublicIdBackfillService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PublicIdBackfillServiceTest extends TestCase
{
    public function testDryRunCountsRemainingRows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnMap([
            ['SELECT COUNT(*) FROM hospital WHERE public_id IS NULL', [], [], 2],
            ['SELECT COUNT(*) FROM secondary_transport WHERE public_id IS NULL', [], [], 0],
            ['SELECT COUNT(*) FROM indication_raw WHERE public_id IS NULL', [], [], 1],
            ['SELECT COUNT(*) FROM mci_case WHERE public_id IS NULL', [], [], 0],
            ['SELECT COUNT(*) FROM allocation WHERE public_id IS NULL', [], [], 5],
        ]);

        $service = new PublicIdBackfillService($connection);
        $result = $service->run(dryRun: true);

        self::assertFalse($result->completed);
        self::assertSame(2, $result->remainingByTable['hospital']);
        self::assertSame(5, $result->remainingByTable['allocation']);
        self::assertSame(0, $result->updatedByTable['hospital']);
    }

    public function testDryRunWithAllTableSelector(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(0);

        $service = new PublicIdBackfillService($connection);

        $resultAll = $service->run(dryRun: true, tables: ['all']);
        $resultNull = $service->run(dryRun: true, tables: null);
        $resultEmpty = $service->run(dryRun: true, tables: []);

        self::assertCount(5, $resultAll->remainingByTable);
        self::assertCount(5, $resultNull->remainingByTable);
        self::assertCount(5, $resultEmpty->remainingByTable);
    }

    public function testInvalidTableThrows(): void
    {
        $connection = $this->createMock(Connection::class);
        $service = new PublicIdBackfillService($connection);

        $this->expectException(\InvalidArgumentException::class);

        $service->run(tables: ['unknown_table']);
    }

    public function testBackfillAssignsPublicIdsForSelectedTable(): void
    {
        $connection = $this->createMock(Connection::class);
        $fetchCalls = 0;
        $connection->method('fetchFirstColumn')
            ->willReturnCallback(function (string $sql) use (&$fetchCalls): array {
                if (!str_contains($sql, 'FROM hospital')) {
                    return [];
                }

                return 0 === $fetchCalls++ ? [10, 11] : [];
            });
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, 'UPDATE hospital')),
                self::callback(static function (array $params): bool {
                    self::assertSame(10, $params['id0']);
                    self::assertSame(11, $params['id1']);
                    self::assertTrue(Uuid::isValid($params['pid0']));
                    self::assertTrue(Uuid::isValid($params['pid1']));

                    return true;
                }),
            )
            ->willReturn(2);
        $connection->method('fetchOne')
            ->willReturnCallback(static fn (string $sql): int => str_contains($sql, 'COUNT') ? 0 : 0);

        $service = new PublicIdBackfillService($connection);
        $result = $service->run(tables: ['hospital']);

        self::assertTrue($result->completed);
        self::assertSame(2, $result->updatedByTable['hospital']);
        self::assertSame(0, $result->remainingByTable['hospital']);
    }

    public function testTableSelectionPreservesConfiguredOrder(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $connection->method('fetchFirstColumn')->willReturn([]);

        $service = new PublicIdBackfillService($connection);
        $result = $service->run(dryRun: true, tables: ['allocation', 'hospital']);

        self::assertSame(['hospital', 'allocation'], array_keys($result->remainingByTable));
    }

    public function testRunControlInterruptsBackfill(): void
    {
        $connection = $this->createMock(Connection::class);
        $control = new PublicIdBackfillRunControl();
        $control->requestStop(\SIGINT);

        $service = new PublicIdBackfillService($connection);

        $this->expectException(PublicIdBackfillInterruptedException::class);

        $service->run(tables: ['hospital'], runControl: $control);
    }

    public function testMaxRuntimePausesAndReportsRemainingTables(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')
            ->willReturnCallback(static function (): array {
                usleep(1_100_000);

                return [42];
            });
        $connection->method('executeStatement')->willReturn(1);
        $connection->method('fetchOne')
            ->willReturnCallback(static fn (string $sql): int => match (true) {
                str_contains($sql, 'FROM hospital') => 1,
                str_contains($sql, 'FROM secondary_transport') => 2,
                str_contains($sql, 'FROM indication_raw') => 3,
                str_contains($sql, 'FROM mci_case') => 4,
                str_contains($sql, 'FROM allocation') => 5,
                default => 0,
            });

        $service = new PublicIdBackfillService($connection);
        $result = $service->run(tables: ['hospital'], batchSize: 1, maxRuntimeSeconds: 1);

        self::assertFalse($result->completed);
        self::assertSame(1, $result->updatedByTable['hospital']);
        self::assertSame(1, $result->remainingByTable['hospital']);
        self::assertSame(2, $result->remainingByTable['secondary_transport']);
        self::assertSame(5, $result->remainingByTable['allocation']);
    }

    public function testGeneratedUuidsAreValidV4(): void
    {
        $uuid = Uuid::v4();

        self::assertSame(36, \strlen($uuid->toRfc4122()));
        self::assertTrue(Uuid::isValid($uuid->toRfc4122()));
    }
}
