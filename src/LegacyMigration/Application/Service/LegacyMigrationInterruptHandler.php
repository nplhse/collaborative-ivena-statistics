<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Service;

use App\LegacyMigration\Application\Exception\LegacyMigrationInterruptedException;
use App\LegacyMigration\Domain\Repository\LegacyMigrationStateRepositoryInterface;
use Doctrine\DBAL\Connection;

final readonly class LegacyMigrationInterruptHandler
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $defaultConnection,
        private LegacyMigrationStateRepositoryInterface $stateRepository,
    ) {
    }

    public function handle(LegacyMigrationInterruptedException $exception, ?int $onlyLegacyImportId = null): void
    {
        $sql = <<<'SQL'
UPDATE legacy_migration_import_mapping
SET status = 'pending',
    error_message = :errorMessage,
    updated_at = :updatedAt
WHERE status = 'running'
SQL;
        $params = [
            'errorMessage' => sprintf('Interrupted (signal %d)', $exception->getSignal()),
            'updatedAt' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
        ];

        if (null !== $onlyLegacyImportId) {
            $sql .= ' AND legacy_import_id = :legacyImportId';
            $params['legacyImportId'] = $onlyLegacyImportId;
        }

        $affected = $this->defaultConnection->executeStatement($sql, $params);

        $this->stateRepository->log(
            'orchestrator',
            'warning',
            sprintf('Migration interrupted by signal %d; reset %d running import(s) to pending.', $exception->getSignal(), $affected),
            $onlyLegacyImportId,
            ['signal' => $exception->getSignal(), 'affectedImports' => $affected],
        );
    }
}
