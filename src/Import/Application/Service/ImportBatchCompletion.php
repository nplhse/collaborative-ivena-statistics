<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\Contracts\ImportBatchSummaryPublisherInterface;
use App\Import\Application\DTO\ImportBatchSummary;
use App\Import\Domain\Enum\ImportBatchRunItemStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Finishes a batch re-import once its last item is done and publishes the summary (ADR 016).
 *
 * The check locks the batch row, so two workers finishing the last items at the same time cannot both
 * miss or both send the completion. It runs in the transaction that ends an item's run, after closing
 * the dispatch phase and during reconciliation. Publishing joins that transaction: this is the one place
 * where a notification is part of the business transaction.
 */
final readonly class ImportBatchCompletion
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private Connection $connection,
        private ImportBatchSummaryPublisherInterface $summaryPublisher,
        #[Target('import')]
        private LoggerInterface $importLogger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Runs the check in its own transaction.
     *
     * @return bool true when this call finished the batch
     */
    public function complete(int $batchRunId, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= $this->clock->now();

        return $this->connection->transactional(
            fn (Connection $connection): bool => $this->completeIfFinished($connection, $batchRunId, $now),
        );
    }

    /**
     * Runs the check inside the caller's transaction. Finishes the batch when the dispatch phase is
     * closed, no item is open any more and it was not finished before.
     *
     * @return bool true when this call finished the batch
     */
    public function completeIfFinished(Connection $connection, int $batchRunId, \DateTimeImmutable $now): bool
    {
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('The batch completion check needs an active transaction.');
        }

        $batch = $connection->fetchAssociative(
            'SELECT dispatch_closed_at, processing_finished_at FROM import_batch_run WHERE id = :id FOR UPDATE',
            ['id' => $batchRunId],
        );

        if (false === $batch || null === $batch['dispatch_closed_at'] || null !== $batch['processing_finished_at']) {
            return false;
        }

        $open = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM import_batch_run_item WHERE run_id = :id AND status IN (:open)',
            [
                'id' => $batchRunId,
                'open' => array_map(static fn (ImportBatchRunItemStatus $status): string => $status->value, ImportBatchRunItemStatus::openStatuses()),
            ],
            ['open' => ArrayParameterType::STRING],
        );

        if ($open > 0) {
            return false;
        }

        $connection->executeStatement(
            'UPDATE import_batch_run SET processing_finished_at = :now WHERE id = :id',
            ['now' => $now, 'id' => $batchRunId],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        $summary = $this->summarize($connection, $batchRunId);
        $this->importLogger->info('import.batch.processing_finished', [
            'batch_run_id' => $batchRunId,
            'completed' => $summary->completed,
            'partial' => $summary->partial,
            'failed' => $summary->failed,
            'superseded' => $summary->superseded,
        ]);

        $this->summaryPublisher->publish($summary);

        return true;
    }

    /**
     * Counts the items of a batch per outcome. Also used to publish the summary again during reconciliation.
     */
    public function summarize(Connection $connection, int $batchRunId): ImportBatchSummary
    {
        $counts = [];
        foreach ($connection->fetchAllAssociative('SELECT status, COUNT(*) AS items FROM import_batch_run_item WHERE run_id = :id GROUP BY status', ['id' => $batchRunId]) as $row) {
            $counts[(string) $row['status']] = (int) $row['items'];
        }

        $count = static fn (ImportBatchRunItemStatus ...$statuses): int => array_sum(array_map(
            static fn (ImportBatchRunItemStatus $status): int => $counts[$status->value] ?? 0,
            $statuses,
        ));

        return new ImportBatchSummary(
            batchRunId: $batchRunId,
            initiatedByUserId: $this->initiator($connection, $batchRunId),
            completed: $count(ImportBatchRunItemStatus::Completed),
            partial: $count(ImportBatchRunItemStatus::Partial),
            failed: $count(ImportBatchRunItemStatus::Failed, ImportBatchRunItemStatus::DispatchFailed, ImportBatchRunItemStatus::Skipped),
            superseded: $count(ImportBatchRunItemStatus::Superseded),
        );
    }

    private function initiator(Connection $connection, int $batchRunId): ?int
    {
        $initiator = $connection->fetchOne(
            "SELECT (options ->> 'initiatedByUserId')::int FROM import_batch_run WHERE id = :id",
            ['id' => $batchRunId],
        );

        return \is_int($initiator) || (\is_string($initiator) && ctype_digit($initiator)) ? (int) $initiator : null;
    }
}
