<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Service;

use App\LegacyMigration\Domain\Repository\LegacyMigrationStateRepositoryInterface;
use Doctrine\DBAL\Connection;

final readonly class LegacyMigrationOrchestrator
{
    public function __construct(
        private Connection $legacyConnection,
        private LegacyUserMigrator $userMigrator,
        private LegacyHospitalMigrator $hospitalMigrator,
        private LegacyImportMigrator $importMigrator,
        private LegacyAllocationMigrator $allocationMigrator,
        private LegacyMigrationStateRepositoryInterface $stateRepository,
    ) {
    }

    public function assertLegacyConnectionReady(): void
    {
        $tables = ['user', 'hospital', 'import', 'allocation', 'state', 'dispatch_area'];
        foreach ($tables as $table) {
            $this->legacyConnection->fetchOne(sprintf('SELECT 1 FROM %s LIMIT 1', $table));
        }
    }

    public function migrate(
        bool $dryRun,
        int $batchSize,
        string $only,
        ?int $legacyImportId,
        bool $resume,
        bool $withProgress,
    ): array {
        $results = [
            'users' => 0,
            'hospitals' => 0,
            'imports' => 0,
            'allocations' => 0,
        ];

        if (\in_array($only, ['users', 'all'], true)) {
            $results['users'] = $this->userMigrator->migrate($dryRun);
        }
        if (\in_array($only, ['hospitals', 'all'], true)) {
            $results['hospitals'] = $this->hospitalMigrator->migrate($dryRun);
        }
        if (\in_array($only, ['imports', 'all'], true)) {
            $results['imports'] = $this->importMigrator->migrate($dryRun, $legacyImportId);
        }
        if (\in_array($only, ['allocations', 'all'], true)) {
            $results['allocations'] = $this->allocationMigrator->migrate($dryRun, $batchSize, $resume, $withProgress, $legacyImportId);
        }

        $this->stateRepository->log('orchestrator', 'info', 'legacy migration completed', null, $results);

        return $results;
    }
}

