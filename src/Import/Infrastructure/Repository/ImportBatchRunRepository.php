<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Repository;

use App\Import\Domain\Entity\ImportBatchRun;
use App\Import\Domain\Enum\ImportBatchRunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    public function findLatestIncomplete(): ?ImportBatchRun
    {
        return $this->createQueryBuilder('r')
            ->where('r.status IN (:statuses)')
            ->setParameter('statuses', [
                ImportBatchRunStatus::Running,
                ImportBatchRunStatus::Interrupted,
                ImportBatchRunStatus::Failed,
            ])
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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

    public function save(ImportBatchRun $run): void
    {
        $this->getEntityManager()->persist($run);
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }
}
