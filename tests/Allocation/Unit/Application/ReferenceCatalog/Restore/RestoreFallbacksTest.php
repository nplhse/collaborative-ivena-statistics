<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\ReferenceCatalog\Restore;

use App\Allocation\Application\ReferenceCatalog\Restore\CatalogMaintenanceGuard;
use App\Allocation\Application\ReferenceCatalog\Restore\ReferenceCatalogDependencyInspector;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class RestoreFallbacksTest extends TestCase
{
    public function testAnInstallationWithoutQueueTableHasNoBusyQueues(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllKeyValue')->willThrowException(
            new TableNotFoundException($this->createStub(DriverException::class), null),
        );

        $guard = new CatalogMaintenanceGuard(new LockFactory(new InMemoryStore()), $connection);

        self::assertSame([], $guard->busyQueues());
    }

    public function testTablesThatDoNotExistAreNotCounted(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn(['allocation', 'hospital']);
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturnCallback(static fn (string $sql): int => str_contains($sql, 'FROM allocation') ? 3 : 0);

        $blockers = new ReferenceCatalogDependencyInspector($connection)->blockers();

        self::assertCount(1, $blockers);
        self::assertSame('allocation', $blockers[0]->table);
        self::assertSame(3, $blockers[0]->rows);
    }
}
