<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Allocation\Infrastructure\Repository\AllocationRepository;
use App\Allocation\Infrastructure\Repository\MciCaseRepository;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Repository\ImportRejectRepository;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
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
        private MciCaseRepository $mciCaseRepository,
        private AllocationStatsProjectionRebuildInterface $statsProjectionRebuilder,
        private ProjectionOverviewChangeDetectorInterface $projectionOverviewChangeDetector,
        private MaterializedViewRefresherInterface $materializedViewRefresher,
        private LoggerInterface $importLogger,
        private ImportFileStorage $fileStorage,
    ) {
    }

    public function removeAll(Import $import): void
    {
        $importId = (int) $import->getId();
        $needsMaterializedViewRefresh = $this->projectionOverviewChangeDetector->willRemoveHospitalsFromProjection($importId);

        $counts = $this->em->wrapInTransaction(function () use ($import, $importId): array {
            $assessmentIds = $this->collectAssessmentIdsForImport($importId);

            return [
                'projection' => $this->statsProjectionRebuilder->deleteForImport($importId),
                'rejects' => $this->importRejectRepository->deleteByImport($import),
                'allocations' => $this->allocationRepository->deleteByImport($import),
                'assessments' => $this->deleteAssessmentsByIds($assessmentIds),
                'mci_cases' => $this->mciCaseRepository->deleteByImport($import),
            ];
        });

        if ($needsMaterializedViewRefresh) {
            $this->materializedViewRefresher->refresh([StatisticsMaterializedViewGroups::OVERVIEW]);
            $this->importLogger->info('statistics_materialized_view.refreshed_after_structural_change', [
                'import_id' => $importId,
                'reason' => 'hospital_removed',
            ]);
        }

        $this->logCleanup(
            $importId,
            $counts['projection'],
            $counts['rejects'],
            $counts['assessments'],
            $counts['allocations'],
            $counts['mci_cases'],
        );
    }

    public function deleteRejectFile(Import $import): void
    {
        $this->fileStorage->delete(
            $import->getRejectFilePath(),
            'import.reject_file.deleted',
            (int) $import->getId(),
        );
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

        if ($deletedMciCases > 0) {
            $this->importLogger->info('import.mci_cases.cleared', [
                'import_id' => $importId,
                'deleted' => $deletedMciCases,
            ]);
        }
    }
}
