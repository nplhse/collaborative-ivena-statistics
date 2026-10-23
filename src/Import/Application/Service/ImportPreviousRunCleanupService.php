<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Domain\Entity\Import;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes the result data of earlier runs (or of an earlier attempt of the same run) before processing.
 *
 * The deletion is a commit of processing results: while a run is active it runs inside the
 * commit guard, so a superseded run can never delete data of a newer run.
 */
final readonly class ImportPreviousRunCleanupService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ImportRelatedDataCleanupService $relatedDataCleanup,
        private ImportRunCommitGuard $commitGuard,
    ) {
    }

    public function cleanup(Import $import): void
    {
        $importId = (int) $import->getId();
        $rejectFilePath = $import->getRejectFilePath();

        $removal = $this->commitGuard->transactional($this->em, function () use ($import): \App\Import\Application\DTO\ImportDataRemoval {
            $removal = $this->relatedDataCleanup->removeRows($import);
            $import->resetForReimport();
            $this->em->flush();

            return $removal;
        });

        $this->relatedDataCleanup->completeRemoval($import, $removal, false);
        $this->relatedDataCleanup->deleteRejectFileAt($rejectFilePath, $importId);
    }
}
