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

    public function delete(Import $import): void
    {
        $importId = (int) $import->getId();

        $this->relatedDataCleanup->removeAll($import);
        $this->relatedDataCleanup->deleteRejectFile($import);
        $this->relatedDataCleanup->deleteSourceFile($import);
        $this->importBatchRunItemRepository->deleteByImportId($importId);

        $this->em->remove($import);
        $this->em->flush();
    }
}
