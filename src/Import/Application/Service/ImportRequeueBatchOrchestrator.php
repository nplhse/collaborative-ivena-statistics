<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\DTO\ImportRequeueBatchOptions;
use App\Import\Application\DTO\ImportRequeueBatchSummary;
use App\Import\Application\DTO\ImportRequeueItemResult;
use App\Import\Application\Exception\DispatchException;
use App\Import\Application\Exception\ImportCreatorMissingException;
use App\Import\Application\Exception\ImportNotFoundException;
use App\Import\Application\Exception\ImportRequeueInterruptedException;
use App\Import\Application\ImportDispatchExitCode;
use App\Import\Domain\Entity\ImportBatchRun;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Enum\ImportBatchRunItemStatus;
use App\Import\Domain\Enum\ImportBatchRunStatus;
use App\Import\Infrastructure\Repository\ImportBatchRunRepository;
use App\Import\Infrastructure\Repository\ImportRepository;

final readonly class ImportRequeueBatchOrchestrator
{
    public function __construct(
        private ImportRepository $importRepository,
        private ImportBatchRunRepository $batchRunRepository,
        private ImportAllocationsDispatcher $dispatcher,
        private ImportRequeueResumeResolver $resumeResolver,
    ) {
    }

    public function run(
        ImportRequeueBatchOptions $options,
        ?ImportRequeueRunControl $runControl = null,
    ): ImportRequeueBatchSummary {
        $imports = $this->importRepository->findIdsForRequeue(
            fromId: $options->fromId,
            onlyId: $options->onlyId,
            limit: $options->limit,
        );

        if ($options->dryRun) {
            return $this->runDry($imports);
        }

        $run = $this->resolveRun($options);
        $slice = $this->resumeResolver->resolveSlice($imports, $run, $options->maxRetriesPerImport);
        $lastItem = $run instanceof ImportBatchRun ? $run->getLastItem() : null;

        if ([] === $slice && $run instanceof ImportBatchRun && $lastItem instanceof ImportBatchRunItem && ($lastItem->getAttemptCount() >= $options->maxRetriesPerImport && \in_array($lastItem->getStatus(), [
            ImportBatchRunItemStatus::DispatchFailed,
            ImportBatchRunItemStatus::Interrupted,
            ImportBatchRunItemStatus::Running,
        ], true))) {
            $lastItem->markSkipped(sprintf(
                'Max retries (%d) exceeded for import #%d',
                $options->maxRetriesPerImport,
                $lastItem->getImportId(),
            ));
            $run->setStatus(ImportBatchRunStatus::Failed);
            $run->setFinishedAt(new \DateTimeImmutable());
            $this->batchRunRepository->flush();

            return new ImportRequeueBatchSummary(
                exitCode: ImportDispatchExitCode::CRITICAL,
                skipped: 1,
                runId: $run->getId(),
                maxRetriesExceeded: true,
            );
        }

        if (!$run instanceof ImportBatchRun) {
            $run = new ImportBatchRun($options->toArray());
            $this->batchRunRepository->save($run);
            $this->batchRunRepository->flush();
        }

        $dispatched = 0;
        $failed = 0;
        $skipped = 0;
        $results = [];
        $currentItem = null;

        try {
            foreach ($slice as $import) {
                $runControl?->throwIfStopRequested();

                $item = $this->resolveItemForImport($run, $import);
                $item->markRunning();
                $run->addItem($item);
                $this->batchRunRepository->save($run);
                $this->batchRunRepository->flush();
                $currentItem = $item;

                if ($item->getAttemptCount() > $options->maxRetriesPerImport) {
                    $item->markSkipped(sprintf(
                        'Max retries (%d) exceeded for import #%d',
                        $options->maxRetriesPerImport,
                        $import['id'],
                    ));
                    $run->setStatus(ImportBatchRunStatus::Failed);
                    $run->setFinishedAt(new \DateTimeImmutable());
                    $this->batchRunRepository->flush();

                    return new ImportRequeueBatchSummary(
                        exitCode: ImportDispatchExitCode::CRITICAL,
                        skipped: 1,
                        runId: $run->getId(),
                        maxRetriesExceeded: true,
                        results: [
                            new ImportRequeueItemResult(
                                $import['id'],
                                $import['name'],
                                $import['filePath'],
                                'skipped',
                            ),
                        ],
                    );
                }

                try {
                    $this->dispatcher->dispatch($import['id']);
                    $runControl?->throwIfStopRequested();
                    $item->markQueued();
                    ++$dispatched;
                    $results[] = new ImportRequeueItemResult(
                        $import['id'],
                        $import['name'],
                        $import['filePath'],
                        'dispatched',
                    );
                } catch (ImportNotFoundException|ImportCreatorMissingException|DispatchException $e) {
                    $item->markDispatchFailed($e->getMessage());
                    ++$failed;
                    $results[] = new ImportRequeueItemResult(
                        $import['id'],
                        $import['name'],
                        $import['filePath'],
                        'failed',
                    );
                }

                $this->batchRunRepository->flush();
                $currentItem = null;
            }

            $run->setStatus(ImportBatchRunStatus::Finished);
            $run->setFinishedAt(new \DateTimeImmutable());
            $this->batchRunRepository->flush();

            $exitCode = $failed > 0 ? ImportDispatchExitCode::FAILURE : ImportDispatchExitCode::SUCCESS;

            return new ImportRequeueBatchSummary(
                exitCode: $exitCode,
                dispatched: $dispatched,
                failed: $failed,
                skipped: $skipped,
                runId: $run->getId(),
                results: $results,
            );
        } catch (ImportRequeueInterruptedException) {
            if ($currentItem instanceof ImportBatchRunItem) {
                $currentItem->markInterrupted();
            }
            $run->setStatus(ImportBatchRunStatus::Interrupted);
            $run->setFinishedAt(new \DateTimeImmutable());
            $this->batchRunRepository->flush();

            return new ImportRequeueBatchSummary(
                exitCode: ImportDispatchExitCode::CRITICAL,
                dispatched: $dispatched,
                failed: $failed,
                skipped: $skipped,
                runId: $run->getId(),
                interrupted: true,
                results: $results,
            );
        }
    }

    /**
     * @param list<array{id: int, name: ?string, filePath: ?string}> $imports
     */
    private function runDry(array $imports): ImportRequeueBatchSummary
    {
        $results = [];
        foreach ($imports as $import) {
            $results[] = new ImportRequeueItemResult(
                $import['id'],
                $import['name'],
                $import['filePath'],
                'would_dispatch',
            );
        }

        return new ImportRequeueBatchSummary(
            exitCode: ImportDispatchExitCode::SUCCESS,
            wouldDispatch: \count($results),
            results: $results,
        );
    }

    private function resolveRun(ImportRequeueBatchOptions $options): ?ImportBatchRun
    {
        if (null !== $options->runId) {
            return $this->batchRunRepository->find($options->runId);
        }

        if ($options->resume) {
            return $this->batchRunRepository->findLatestIncomplete();
        }

        return null;
    }

    /**
     * @param array{id: int, name: ?string, filePath: ?string} $import
     */
    private function resolveItemForImport(ImportBatchRun $run, array $import): ImportBatchRunItem
    {
        $lastItem = $run->getLastItem();
        if ($lastItem instanceof ImportBatchRunItem
            && $lastItem->getImportId() === $import['id']
            && \in_array($lastItem->getStatus(), [
                ImportBatchRunItemStatus::Running,
                ImportBatchRunItemStatus::DispatchFailed,
                ImportBatchRunItemStatus::Interrupted,
            ], true)) {
            return $lastItem;
        }

        return new ImportBatchRunItem($import['id'], $import['name']);
    }
}
