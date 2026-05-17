<?php

declare(strict_types=1);

namespace App\Tests\LegacyMigration\Unit\Application;

use App\LegacyMigration\Application\Exception\LegacyMigrationInterruptedException;
use App\LegacyMigration\Application\Service\LegacyMigrationInterruptHandler;
use App\LegacyMigration\Domain\Repository\LegacyMigrationStateRepositoryInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class LegacyMigrationInterruptHandlerTest extends TestCase
{
    public function testResetsRunningImportsToPending(): void
    {
        $connection = $this->createMock(Connection::class);
        $stateRepository = $this->createMock(LegacyMigrationStateRepositoryInterface::class);

        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, "status = 'pending'") && str_contains($sql, "status = 'running'")),
                self::callback(static fn (array $params): bool => str_contains((string) $params['errorMessage'], 'Interrupted (signal 2)')),
            )
            ->willReturn(1);

        $stateRepository->expects(self::once())->method('log');

        $handler = new LegacyMigrationInterruptHandler($connection, $stateRepository);
        $handler->handle(new LegacyMigrationInterruptedException(\SIGINT));
    }

    public function testScopesUpdateToLegacyImportIdWhenProvided(): void
    {
        /** @var Connection&MockObject $connection */
        $connection = $this->createMock(Connection::class);
        $stateRepository = $this->createMock(LegacyMigrationStateRepositoryInterface::class);

        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('legacy_import_id = :legacyImportId'),
                self::callback(static fn (array $params): bool => 42 === ($params['legacyImportId'] ?? null)),
            )
            ->willReturn(1);

        $handler = new LegacyMigrationInterruptHandler($connection, $stateRepository);
        $handler->handle(new LegacyMigrationInterruptedException(\SIGTERM), 42);
    }
}
