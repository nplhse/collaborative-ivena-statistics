<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Repository;

use App\Import\Domain\Entity\ImportBatchRun;
use App\Import\Domain\Enum\ImportBatchRunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    public function save(ImportBatchRun $run): void
    {
        $this->getEntityManager()->persist($run);
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }
}
