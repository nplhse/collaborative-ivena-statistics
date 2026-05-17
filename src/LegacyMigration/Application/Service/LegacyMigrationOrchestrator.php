<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Service;

use App\LegacyMigration\Application\Contract\LegacyMigrationProgressInterface;
use App\LegacyMigration\Domain\Repository\LegacyMigrationStateRepositoryInterface;
use Doctrine\DBAL\Connection;

final readonly class LegacyMigrationOrchestrator
{
    /** @psalm-suppress PossiblyUnusedMethod */
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

    /**
     * @return array{users:int, hospitals:int, imports:int, allocations:int}
     */
    public function migrate(
        bool $dryRun,
        int $batchSize,
        string $only,
        ?int $legacyImportId,
        bool $resume,
        LegacyMigrationProgressInterface $progress,
        LegacyMigrationRunControl $runControl,
        bool $skipProjection = false,
        bool $force = false,
    ): array {
        /** @var array{users:int, hospitals:int, imports:int, allocations:int} $results */
        $results = [
            'users' => 0,
            'hospitals' => 0,
            'imports' => 0,
            'allocations' => 0,
        ];

        if (\in_array($only, ['users', 'all'], true)) {
            $runControl->throwIfStopRequested();
            $userTotal = (int) $this->legacyConnection->fetchOne('SELECT COUNT(*) FROM user');
            $progress->startPhase('Users', $userTotal);
            $results['users'] = $this->userMigrator->migrate($dryRun, $progress, $runControl);
            $progress->finishPhase();
        }
        if (\in_array($only, ['hospitals', 'all'], true)) {
            $runControl->throwIfStopRequested();
            $hospitalTotal = (int) $this->legacyConnection->fetchOne('SELECT COUNT(*) FROM hospital');
            $progress->startPhase('Hospitals', $hospitalTotal);
            $results['hospitals'] = $this->hospitalMigrator->migrate($dryRun, $progress, $runControl);
            $progress->finishPhase();
        }
        if (\in_array($only, ['imports', 'all'], true)) {
            $runControl->throwIfStopRequested();
            $importTotal = $this->countLegacyImports($legacyImportId);
            $progress->startPhase('Imports', $importTotal);
            $results['imports'] = $this->importMigrator->migrate($dryRun, $progress, $runControl, $legacyImportId);
            $progress->finishPhase();
        }
        if (\in_array($only, ['allocations', 'all'], true)) {
            $runControl->throwIfStopRequested();
            $results['allocations'] = $this->allocationMigrator->migrate(
                $dryRun,
                $batchSize,
                $resume,
                $progress,
                $runControl,
                $legacyImportId,
                $skipProjection,
                $force,
            );
        }

        $runControl->throwIfStopRequested();
        $this->stateRepository->log('orchestrator', 'info', 'legacy migration completed', null, $results);

        return $results;
    }

    private function countLegacyImports(?int $legacyImportId): int
    {
        if (null !== $legacyImportId) {
            return 1;
        }

        return (int) $this->legacyConnection->fetchOne('SELECT COUNT(*) FROM import');
    }
}
