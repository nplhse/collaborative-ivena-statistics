<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Query;

use App\Import\Application\DTO\ImportBatchProgress;
use App\Import\Domain\Enum\ImportBatchRunItemStatus;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\DBAL\Connection;

/**
 * Progress of a batch for the user who started it, or for administrators. Other users get null,
 * so batch ids reveal nothing about batches of others.
 */
final readonly class ImportBatchProgressQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function forUser(int $batchRunId, User $user): ?ImportBatchProgress
    {
        $batch = $this->connection->fetchAssociative(
            "SELECT (options ->> 'initiatedByUserId') AS initiator, processing_finished_at FROM import_batch_run WHERE id = :id",
            ['id' => $batchRunId],
        );
        if (false === $batch) {
            return null;
        }

        $isInitiator = null !== $batch['initiator'] && (string) $user->getId() === (string) $batch['initiator'];
        if (!$isInitiator && !\in_array(UserRole::ADMIN, $user->getRoles(), true)) {
            return null;
        }

        $counts = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT status, COUNT(*) AS items FROM import_batch_run_item WHERE run_id = :id GROUP BY status',
            ['id' => $batchRunId],
        ) as $row) {
            $counts[(string) $row['status']] = (int) $row['items'];
        }

        $count = static fn (ImportBatchRunItemStatus ...$statuses): int => array_sum(array_map(
            static fn (ImportBatchRunItemStatus $status): int => $counts[$status->value] ?? 0,
            $statuses,
        ));

        return new ImportBatchProgress(
            batchRunId: $batchRunId,
            total: array_sum($counts),
            succeeded: $count(ImportBatchRunItemStatus::Completed, ImportBatchRunItemStatus::Partial),
            failed: $count(ImportBatchRunItemStatus::Failed, ImportBatchRunItemStatus::DispatchFailed, ImportBatchRunItemStatus::Skipped),
            superseded: $count(ImportBatchRunItemStatus::Superseded),
            finished: null !== $batch['processing_finished_at'],
        );
    }
}
