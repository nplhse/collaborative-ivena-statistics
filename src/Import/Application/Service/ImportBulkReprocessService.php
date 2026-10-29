<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\DTO\ImportBulkActionResult;
use App\Import\Application\DTO\ImportBulkSkipReason;
use App\Import\Application\Exception\DispatchException;
use App\Import\Application\Exception\ImportCreatorMissingException;
use App\Import\Application\Exception\ImportNotFoundException;
use App\Import\Application\Exception\ImportTypeMismatchException;
use App\Import\Application\Exception\UnsupportedImportTypeException;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportBatchRun;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Repository\ImportBatchRunRepository;
use App\Import\Infrastructure\Repository\ImportRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Queues selected imports for reprocessing as one batch (#720).
 *
 * Each import goes through the same dispatch as the single "Reprocess import" action. The batch groups
 * the runs: they send no notification of their own, the batch sends one summary when the last run ends
 * (ADR 016). Imports that may not or cannot be reprocessed are skipped and reported; a failed dispatch
 * only skips that import.
 */
final readonly class ImportBulkReprocessService
{
    public function __construct(
        private ImportRepository $importRepository,
        private ImportBatchRunRepository $batchRunRepository,
        private ImportStartDispatcher $dispatcher,
        private ImportBatchCompletion $batchCompletion,
        private ImportCreatorPermissionCheck $creatorPermissionCheck,
        private ImportFileStorage $fileStorage,
        #[Target('import')]
        private LoggerInterface $importLogger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<int>              $importIds    selected ids, validated and de-duplicated
     * @param \Closure(Import): bool $canManage    whether the user may change the import at all (delete rule)
     * @param \Closure(Import): bool $canReprocess whether the user may reprocess it (also needs creator and file)
     */
    public function reprocess(array $importIds, ?int $initiatedByUserId, \Closure $canManage, \Closure $canReprocess): ImportBulkActionResult
    {
        $skipped = [];
        $eligible = [];
        foreach ($this->load($importIds) as $id => $import) {
            if (null === $import) {
                $skipped[ImportBulkSkipReason::Forbidden->value] = ($skipped[ImportBulkSkipReason::Forbidden->value] ?? 0) + 1;

                continue;
            }

            $reason = $this->skipReason($import, $canManage, $canReprocess);
            if ($reason instanceof ImportBulkSkipReason) {
                $skipped[$reason->value] = ($skipped[$reason->value] ?? 0) + 1;

                continue;
            }
            $eligible[$id] = $import;
        }

        if ([] === $eligible) {
            return new ImportBulkActionResult(0, $skipped);
        }

        $run = new ImportBatchRun([
            'source' => ImportBatchRun::SOURCE_UI,
            'initiatedByUserId' => $initiatedByUserId,
            'importIds' => array_keys($eligible),
        ]);
        $this->batchRunRepository->save($run);
        $this->batchRunRepository->flush();

        $queued = 0;
        try {
            foreach ($eligible as $id => $import) {
                $run = $this->batchRunRepository->managed($run);
                $item = new ImportBatchRunItem($id, $import->getName());
                $item->markRunning();
                $run->addItem($item);
                $this->batchRunRepository->flush();

                try {
                    // Registering the run marks the item as queued in the same transaction.
                    $this->dispatcher->dispatch($id, $initiatedByUserId, ImportRunTrigger::BulkReprocess, $item);
                    ++$queued;
                } catch (ImportNotFoundException|ImportCreatorMissingException|ImportTypeMismatchException|UnsupportedImportTypeException|DispatchException $e) {
                    $this->batchRunRepository->managedItem($item)->markDispatchFailed($e->getMessage());
                    $skipped[ImportBulkSkipReason::DispatchFailed->value] = ($skipped[ImportBulkSkipReason::DispatchFailed->value] ?? 0) + 1;
                    $this->importLogger->error('import.bulk_reprocess.dispatch_failed', [
                        'id' => $id,
                        'batch_run_id' => $run->getId(),
                        'ex' => $e::class,
                        'msg' => $e->getMessage(),
                    ]);
                }

                $this->batchRunRepository->flush();
            }
        } finally {
            // Always close the dispatch phase, so the batch can complete and is never resumed from the console.
            $run = $this->batchRunRepository->managed($run);
            $run->closeDispatch($this->clock->now());
            $this->batchRunRepository->flush();
        }

        $batchRunId = (int) $run->getId();
        // Runs may all have finished during the dispatch phase; then nobody else completes the batch.
        $this->batchCompletion->complete($batchRunId);

        $this->importLogger->info('import.bulk_reprocess.queued', [
            'batch_run_id' => $batchRunId,
            'initiated_by' => $initiatedByUserId,
            'queued' => $queued,
            'skipped' => $skipped,
        ]);

        return new ImportBulkActionResult($queued, $skipped, $batchRunId);
    }

    /**
     * @param list<int> $importIds
     *
     * @return array<int, Import|null> in the selected order; null for ids that do not exist
     */
    private function load(array $importIds): array
    {
        $found = [];
        foreach ($this->importRepository->findBy(['id' => $importIds]) as $import) {
            $found[(int) $import->getId()] = $import;
        }

        $ordered = [];
        foreach ($importIds as $id) {
            $ordered[$id] = $found[$id] ?? null;
        }

        return $ordered;
    }

    /**
     * @param \Closure(Import): bool $canManage
     * @param \Closure(Import): bool $canReprocess
     */
    private function skipReason(Import $import, \Closure $canManage, \Closure $canReprocess): ?ImportBulkSkipReason
    {
        // Checked first, so nothing about imports the user may not change is revealed.
        if (!$canManage($import)) {
            return ImportBulkSkipReason::Forbidden;
        }

        if (\in_array($import->getStatus(), [ImportStatus::PENDING, ImportStatus::RUNNING], true)) {
            return ImportBulkSkipReason::Busy;
        }

        if ($canReprocess($import)) {
            return null;
        }

        if (!$this->fileStorage->exists($import->getFilePath())) {
            return ImportBulkSkipReason::SourceMissing;
        }

        return $this->creatorPermissionCheck->failureFor($import) instanceof \App\Import\Domain\Enum\ImportRunReasonCode
            ? ImportBulkSkipReason::CreatorPermission
            : ImportBulkSkipReason::Forbidden;
    }
}
