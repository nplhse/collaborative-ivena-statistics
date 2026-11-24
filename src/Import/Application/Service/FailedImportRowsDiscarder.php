<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\Contracts\FailedImportRowsReaderInterface;
use App\Import\Application\DTO\FailedImportRowsAction;
use App\Import\Application\DTO\FailedImportRowsCandidate;
use App\Import\Application\DTO\ImportDataRemoval;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Domain\Enum\ImportStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cleans up rows that failed imports left behind before #1551. Reprocessing affected imports stays a separate step.
 */
final readonly class FailedImportRowsDiscarder
{
    public function __construct(
        private FailedImportRowsReaderInterface $query,
        private ImportFileStorage $fileStorage,
        private ImportRelatedDataCleanupService $cleanup,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<FailedImportRowsCandidate>
     */
    public function candidates(): array
    {
        $candidates = [];
        foreach ($this->query->findCandidates() as $row) {
            $importId = (int) $row['id'];
            $replacedRows = (int) $row['replaced_rows'];

            $candidates[] = new FailedImportRowsCandidate(
                importId: $importId,
                hospitalName: $row['hospital_name'],
                periodStart: $row['export_starts_at'],
                periodEnd: $row['export_ends_at'],
                allocations: (int) $row['allocations'],
                closureIntervals: (int) $row['closure_intervals'],
                projectionRows: (int) $row['projection_rows'],
                replacedRows: $replacedRows,
                sourceFilePresent: $this->fileStorage->exists($row['file_path']),
                lastReasonCode: null !== $row['last_reason_code'] ? ImportRunReasonCode::tryFrom($row['last_reason_code']) : null,
                hospitalLeavesStatistics: \in_array($row['hospital_leaves_statistics'], [true, 1, '1', 't'], true),
                importsToReprocess: $replacedRows > 0
                    ? $this->query->findOlderOverlappingImportIds($importId, (int) $row['hospital_id'], $row['created_at'], $row['export_starts_at'], $row['export_ends_at'])
                    : [],
            );
        }

        return $candidates;
    }

    /**
     * @param list<FailedImportRowsCandidate> $candidates
     *
     * @return list<int> ids of the imports whose rows were removed
     */
    public function discard(array $candidates): array
    {
        $discarded = [];
        $refreshOverview = false;

        foreach ($candidates as $candidate) {
            if (FailedImportRowsAction::Discard !== $candidate->action()) {
                continue;
            }

            $import = $this->em->find(Import::class, $candidate->importId);
            if (!$import instanceof Import) {
                continue;
            }

            $removal = $this->removeIfStillFailed($import);
            if ($removal instanceof ImportDataRemoval) {
                $refreshOverview = $this->cleanup->completeRemoval($import, $removal, true, false) || $refreshOverview;
                $discarded[] = $candidate->importId;
            }

            $this->em->clear();
        }

        if ($refreshOverview) {
            $this->cleanup->refreshOverview(['import_ids' => $discarded]);
        }

        return $discarded;
    }

    /**
     * Locks the import, so a reprocess started in the meantime keeps its rows.
     */
    private function removeIfStillFailed(Import $import): ?ImportDataRemoval
    {
        return $this->em->wrapInTransaction(function () use ($import): ?ImportDataRemoval {
            ImportRunCommitGuard::lockCurrentRunId($this->em->getConnection(), (int) $import->getId());
            $this->em->refresh($import);

            return ImportStatus::FAILED === $import->getStatus()
                ? $this->cleanup->removeRows($import, keepRejects: true)
                : null;
        });
    }
}
