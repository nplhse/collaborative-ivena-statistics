<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Repository;

use App\Import\Domain\Entity\ImportBatchRun;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Enum\ImportBatchRunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportBatchRun>
 */
final class ImportBatchRunRepository extends ServiceEntityRepository
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportBatchRun::class);
    }

    /**
     * The latest console batch that `app:import:requeue-all --resume` may continue. Bulk reprocess
     * batches from the import list (`source: ui`) are never resumed from the console.
     */
    public function findLatestIncomplete(): ?ImportBatchRun
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT id FROM import_batch_run WHERE status IN (:statuses) AND (options ->> 'source') IS DISTINCT FROM :ui ORDER BY id DESC LIMIT 1",
            [
                'statuses' => [
                    ImportBatchRunStatus::Running->value,
                    ImportBatchRunStatus::Interrupted->value,
                    ImportBatchRunStatus::Failed->value,
                ],
                'ui' => ImportBatchRun::SOURCE_UI,
            ],
            ['statuses' => ArrayParameterType::STRING],
        );

        return false === $id ? null : $this->find((int) $id);
    }

    /**
     * Batches whose dispatch phase is closed but whose processing is not marked as finished yet.
     *
     * @return list<int>
     */
    public function findIdsAwaitingCompletion(): array
    {
        return array_map(intval(...), $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM import_batch_run WHERE dispatch_closed_at IS NOT NULL AND processing_finished_at IS NULL ORDER BY id',
        ));
    }

    /**
     * Batches whose processing finished in the given range, oldest first.
     *
     * @return list<int>
     */
    public function findIdsFinishedProcessingBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_map(intval(...), $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM import_batch_run WHERE processing_finished_at BETWEEN :from AND :to ORDER BY processing_finished_at, id',
            ['from' => $from, 'to' => $to],
            ['from' => Types::DATETIME_IMMUTABLE, 'to' => Types::DATETIME_IMMUTABLE],
        ));
    }

    /**
     * Returns the managed instance of a batch. Processing an import in the same process (sync transport,
     * tests) clears the entity manager per chunk, which detaches entities held by the caller.
     */
    public function managed(ImportBatchRun $run): ImportBatchRun
    {
        if ($this->getEntityManager()->contains($run)) {
            return $run;
        }

        return $this->find($run->getId()) ?? throw new \LogicException(\sprintf('Import batch run #%d is gone.', (int) $run->getId()));
    }

    /**
     * @see managed()
     */
    public function managedItem(ImportBatchRunItem $item): ImportBatchRunItem
    {
        $em = $this->getEntityManager();
        if ($em->contains($item)) {
            return $item;
        }

        return $em->find(ImportBatchRunItem::class, $item->getId()) ?? throw new \LogicException(\sprintf('Import batch run item #%d is gone.', (int) $item->getId()));
    }

    public function save(ImportBatchRun $run): void
    {
        $this->getEntityManager()->persist($run);
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }
}
