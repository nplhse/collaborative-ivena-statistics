<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\DTO\ImportSummary;
use App\Import\Domain\Entity\Import;
use App\Statistics\Application\Projection\AllocationProjectionDeduplicator;
use App\Statistics\Application\Projection\Dto\DeduplicationResult;
use Psr\Log\LoggerInterface;

final readonly class ImportAllocationDeduplicationService
{
    public function __construct(
        private AllocationProjectionDeduplicator $deduplicator,
        private LoggerInterface $importLogger,
    ) {
    }

    public function deduplicateForImport(Import $import): DeduplicationResult
    {
        $importId = $import->getId();
        $hospital = $import->getHospital();
        $hospitalId = $hospital?->getId();

        if (null === $importId || null === $hospitalId) {
            throw new \InvalidArgumentException('Import must be persisted with a hospital before deduplication.');
        }

        $result = $this->deduplicator->executeForHospital($hospitalId, $importId);

        $this->importLogger->info('import.deduplicate.finished', [
            'import_id' => $importId,
            'hospital_id' => $hospitalId,
            'rows_deduplicated' => $result->totalDeletedAllocations(),
            'rows_deduplicated_discarded' => $result->deletedFromCurrentImport,
            'rows_deduplicated_replaced' => $result->deletedFromOtherImports,
        ]);

        return $result;
    }

    public function adjustSummary(ImportSummary $summary, DeduplicationResult $result): ImportSummary
    {
        return new ImportSummary(
            $summary->total,
            max(0, $summary->ok - $result->deletedFromCurrentImport),
            $summary->rejected,
        );
    }

    public function applyDeduplicationStats(Import $import, DeduplicationResult $result): void
    {
        $import
            ->setRowsDeduplicated($result->totalDeletedAllocations())
            ->setRowsDeduplicatedDiscarded($result->deletedFromCurrentImport)
            ->setRowsDeduplicatedReplaced($result->deletedFromOtherImports);
    }
}
