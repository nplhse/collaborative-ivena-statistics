<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Repository\ImportBatchRunItemRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ImportDeletionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ImportRelatedDataCleanupService $relatedDataCleanup,
        private ImportBatchRunItemRepository $importBatchRunItemRepository,
    ) {
    }

    /**
     * Deletes the import with its result rows, files and batch items.
     *
     * @param bool $refreshMaterializedView false defers a needed view refresh to the caller (bulk deletion)
     *
     * @return bool true when a view refresh is needed but was deferred
     */
    public function delete(Import $import, bool $refreshMaterializedView = true): bool
    {
        $importId = (int) $import->getId();

        $deferredRefresh = $this->relatedDataCleanup->removeAll($import, refreshMaterializedView: $refreshMaterializedView);
        $this->relatedDataCleanup->deleteRejectFile($import);
        $this->relatedDataCleanup->deleteSourceFile($import);
        $this->importBatchRunItemRepository->deleteByImportId($importId);

        $this->em->remove($import);
        $this->em->flush();

        return $deferredRefresh;
    }
}
