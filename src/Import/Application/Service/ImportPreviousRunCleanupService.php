<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Domain\Entity\Import;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ImportPreviousRunCleanupService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ImportRelatedDataCleanupService $relatedDataCleanup,
    ) {
    }

    public function cleanup(Import $import): void
    {
        $this->relatedDataCleanup->removeAll($import);
        $this->relatedDataCleanup->deleteRejectFile($import);
        $import->resetForReimport();
        $this->em->flush();
    }
}
