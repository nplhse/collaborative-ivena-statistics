<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Domain\Entity\ImportBatchRun;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Enum\ImportBatchRunItemStatus;

final class ImportRequeueResumeResolver
{
    /**
     * @param list<array{id: int, name: ?string, filePath: ?string}> $imports
     *
     * @return list<array{id: int, name: ?string, filePath: ?string}>
     */
    public function resolveSlice(
        array $imports,
        ?ImportBatchRun $run,
        int $maxRetriesPerImport,
    ): array {
        if ([] === $imports || !$run instanceof ImportBatchRun) {
            return $imports;
        }

        $lastItem = $run->getLastItem();
        if (!$lastItem instanceof ImportBatchRunItem) {
            return $imports;
        }

        return match ($lastItem->getStatus()) {
            ImportBatchRunItemStatus::Running,
            ImportBatchRunItemStatus::Interrupted,
            ImportBatchRunItemStatus::DispatchFailed => $this->sliceForRetry($imports, $lastItem, $maxRetriesPerImport),
            ImportBatchRunItemStatus::Queued,
            ImportBatchRunItemStatus::Skipped => $this->sliceAfterImport($imports, $lastItem->getImportId()),
            ImportBatchRunItemStatus::Pending => $this->sliceFromImport($imports, $lastItem->getImportId()),
        };
    }

    public function resolveStartImportId(ImportBatchRunItem $lastItem, int $maxRetriesPerImport): ?int
    {
        return match ($lastItem->getStatus()) {
            ImportBatchRunItemStatus::Running,
            ImportBatchRunItemStatus::Interrupted,
            ImportBatchRunItemStatus::DispatchFailed => $this->resolveRetryImportId($lastItem, $maxRetriesPerImport),
            ImportBatchRunItemStatus::Queued,
            ImportBatchRunItemStatus::Skipped => $lastItem->getImportId(),
            ImportBatchRunItemStatus::Pending => $lastItem->getImportId(),
        };
    }

    /**
     * @param list<array{id: int, name: ?string, filePath: ?string}> $imports
     *
     * @return list<array{id: int, name: ?string, filePath: ?string}>
     */
    private function sliceForRetry(array $imports, ImportBatchRunItem $lastItem, int $maxRetriesPerImport): array
    {
        if ($lastItem->getAttemptCount() >= $maxRetriesPerImport) {
            return [];
        }

        return $this->sliceFromImport($imports, $lastItem->getImportId());
    }

    /**
     * @param list<array{id: int, name: ?string, filePath: ?string}> $imports
     *
     * @return list<array{id: int, name: ?string, filePath: ?string}>
     */
    private function sliceFromImport(array $imports, int $importId): array
    {
        $index = $this->indexOfImportId($imports, $importId);
        if (null === $index) {
            return [];
        }

        return \array_slice($imports, $index);
    }

    /**
     * @param list<array{id: int, name: ?string, filePath: ?string}> $imports
     *
     * @return list<array{id: int, name: ?string, filePath: ?string}>
     */
    private function sliceAfterImport(array $imports, int $importId): array
    {
        $index = $this->indexOfImportId($imports, $importId);
        if (null === $index) {
            return [];
        }

        return \array_slice($imports, $index + 1);
    }

    /**
     * @param list<array{id: int, name: ?string, filePath: ?string}> $imports
     */
    private function indexOfImportId(array $imports, int $importId): ?int
    {
        foreach ($imports as $index => $import) {
            if ($import['id'] === $importId) {
                return $index;
            }
        }

        return null;
    }

    private function resolveRetryImportId(ImportBatchRunItem $lastItem, int $maxRetriesPerImport): ?int
    {
        if ($lastItem->getAttemptCount() >= $maxRetriesPerImport) {
            return null;
        }

        return $lastItem->getImportId();
    }
}
