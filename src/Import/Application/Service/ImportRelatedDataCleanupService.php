<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Allocation\Infrastructure\Repository\AllocationRepository;
use App\Allocation\Infrastructure\Repository\ClosureIntervalRepository;
use App\Allocation\Infrastructure\Repository\MciCaseRepository;
use App\Import\Application\DTO\ImportDataRemoval;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Repository\ImportRejectRepository;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\Contract\ClosureRebuildRequestInterface;
use App\Statistics\Application\Contract\MaterializedViewRefresherInterface;
use App\Statistics\Application\Contract\ProjectionOverviewChangeDetectorInterface;
use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewGroups;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class ImportRelatedDataCleanupService
{
    public function __construct(
        private EntityManagerInterface $em,
        private Connection $connection,
        private ImportRejectRepository $importRejectRepository,
        private AllocationRepository $allocationRepository,
        private ClosureIntervalRepository $closureIntervalRepository,
        private MciCaseRepository $mciCaseRepository,
        private AllocationStatsProjectionRebuildInterface $statsProjectionRebuilder,
        private ProjectionOverviewChangeDetectorInterface $projectionOverviewChangeDetector,
        private MaterializedViewRefresherInterface $materializedViewRefresher,
        private ClosureRebuildRequestInterface $closureRebuildScheduler,
        private LoggerInterface $importLogger,
        private ImportFileStorage $fileStorage,
    ) {
    }

    /**
     * @param bool $refreshMaterializedView false defers a needed view refresh to the caller (bulk deletion)
     *
     * @return bool true when a view refresh is needed but was deferred
     */
    public function removeAll(Import $import, bool $scheduleRebuild = true, bool $refreshMaterializedView = true): bool
    {
        return $this->completeRemoval($import, $this->removeRows($import), $scheduleRebuild, $refreshMaterializedView);
    }

    /**
     * Deletes the import's result rows in one transaction, or as part of the caller's transaction.
     * Call completeRemoval() after that transaction has committed.
     */
    public function removeRows(Import $import): ImportDataRemoval
    {
        $importId = (int) $import->getId();
        $needsMaterializedViewRefresh = $this->projectionOverviewChangeDetector->willRemoveHospitalsFromProjection($importId);

        $counts = $this->em->wrapInTransaction(function () use ($import, $importId): array {
            $assessmentIds = $this->collectAssessmentIdsForImport($importId);

            return [
                'projection' => $this->statsProjectionRebuilder->deleteForImport($importId),
                'rejects' => $this->importRejectRepository->deleteByImport($import),
                'allocations' => $this->allocationRepository->deleteByImport($import),
                'closure_intervals' => $this->closureIntervalRepository->deleteByImport($import),
                'assessments' => $this->deleteAssessmentsByIds($assessmentIds),
                'mci_cases' => $this->mciCaseRepository->deleteByImport($import),
            ];
        });

        return new ImportDataRemoval($counts, $needsMaterializedViewRefresh);
    }

    /**
     * Follow-up work that must not run inside the deleting transaction: rebuild requests,
     * materialized view refresh (CONCURRENTLY cannot run in a transaction) and logging.
     *
     * @return bool true when a view refresh is needed but was deferred
     */
    public function completeRemoval(Import $import, ImportDataRemoval $removal, bool $scheduleRebuild, bool $refreshMaterializedView = true): bool
    {
        $importId = (int) $import->getId();
        $counts = $removal->counts;

        if ($scheduleRebuild) {
            $hospitalId = $import->getHospital()?->getId();
            $hospitalId = \is_int($hospitalId) ? $hospitalId : null;
            // The import's export period bounds the removed data; without one the whole hospital is rebuilt.
            if ($counts['closure_intervals'] > 0) {
                $this->closureRebuildScheduler->requestAnalysis($hospitalId, $import->getExportStartsAt(), $import->getExportEndsAt());
            } elseif ($counts['allocations'] > 0) {
                $this->closureRebuildScheduler->requestVolume($hospitalId, $import->getExportStartsAt(), $import->getExportEndsAt());
            }
        }

        $deferredRefresh = $removal->needsMaterializedViewRefresh && !$refreshMaterializedView;
        if ($removal->needsMaterializedViewRefresh && $refreshMaterializedView) {
            $this->refreshOverview(['import_id' => $importId]);
        }

        $this->logCleanup(
            $importId,
            $counts['projection'],
            $counts['rejects'],
            $counts['assessments'],
            $counts['allocations'],
            $counts['closure_intervals'],
            $counts['mci_cases'],
        );

        return $deferredRefresh;
    }

    /**
     * Refreshes the overview view after imports left the projection; called once after a bulk deletion.
     *
     * @param array<string, int|list<int>> $context log context
     */
    public function refreshOverview(array $context): void
    {
        $this->materializedViewRefresher->refresh([StatisticsMaterializedViewGroups::OVERVIEW]);
        $this->importLogger->info('statistics_materialized_view.refreshed_after_structural_change', [
            ...$context,
            'reason' => 'hospital_removed',
        ]);
    }

    public function deleteRejectFile(Import $import): void
    {
        $this->deleteRejectFileAt($import->getRejectFilePath(), (int) $import->getId());
    }

    public function deleteRejectFileAt(?string $rejectFilePath, int $importId): void
    {
        $this->fileStorage->delete($rejectFilePath, 'import.reject_file.deleted', $importId);
    }

    public function deleteSourceFile(Import $import): void
    {
        $this->fileStorage->delete(
            $import->getFilePath(),
            'import.source_file.deleted',
            (int) $import->getId(),
        );
    }

    /** @return list<int> */
    private function collectAssessmentIdsForImport(int $importId): array
    {
        $ids = $this->connection->fetchFirstColumn(
            <<<'SQL'
SELECT DISTINCT assessment_id
FROM allocation
WHERE import_id = :importId
  AND assessment_id IS NOT NULL
SQL,
            ['importId' => $importId],
        );

        return array_map(static fn (mixed $id): int => (int) $id, $ids);
    }

    /** @param list<int> $assessmentIds */
    private function deleteAssessmentsByIds(array $assessmentIds): int
    {
        if ([] === $assessmentIds) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, \count($assessmentIds), '?'));

        return $this->connection->executeStatement(
            'DELETE FROM assessment WHERE id IN ('.$placeholders.')',
            $assessmentIds,
        );
    }

    private function logCleanup(
        int $importId,
        int $deletedProjectionRows,
        int $deletedRejects,
        int $deletedAssessments,
        int $deletedAllocations,
        int $deletedClosureIntervals,
        int $deletedMciCases,
    ): void {
        if ($deletedProjectionRows > 0) {
            $this->importLogger->info('import.stats_projection.cleared', [
                'import_id' => $importId,
                'deleted' => $deletedProjectionRows,
            ]);
        }

        if ($deletedRejects > 0) {
            $this->importLogger->info('import.rejects.cleared', [
                'import_id' => $importId,
                'deleted' => $deletedRejects,
            ]);
        }

        if ($deletedAssessments > 0) {
            $this->importLogger->info('import.assessments.cleared', [
                'import_id' => $importId,
                'deleted' => $deletedAssessments,
            ]);
        }

        if ($deletedAllocations > 0) {
            $this->importLogger->info('import.allocations.cleared', [
                'import_id' => $importId,
                'deleted' => $deletedAllocations,
            ]);
        }

        if ($deletedClosureIntervals > 0) {
            $this->importLogger->info('import.closure_intervals.cleared', [
                'import_id' => $importId,
                'deleted' => $deletedClosureIntervals,
            ]);
        }

        if ($deletedMciCases > 0) {
            $this->importLogger->info('import.mci_cases.cleared', [
                'import_id' => $importId,
                'deleted' => $deletedMciCases,
            ]);
        }
    }
}
