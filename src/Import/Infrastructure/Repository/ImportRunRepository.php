<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Repository;

use App\Import\Domain\Entity\ImportRun;
use App\Import\Domain\Enum\ImportRunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportRun>
 */
final class ImportRunRepository extends ServiceEntityRepository
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportRun::class);
    }

    /**
     * Runs of one import, newest first.
     *
     * @return list<ImportRun>
     */
    public function findByImportId(int $importId): array
    {
        /** @var list<ImportRun> $runs */
        $runs = $this->createQueryBuilder('run')
            ->where('IDENTITY(run.import) = :importId')
            ->setParameter('importId', $importId)
            ->orderBy('run.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $runs;
    }

    /**
     * Runs that finished as completed, partial or failed within [$from, $to], oldest first.
     *
     * @return list<ImportRun>
     */
    public function findFinishedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<ImportRun> $runs */
        $runs = $this->createQueryBuilder('run')
            ->andWhere('run.status IN (:statuses)')
            ->andWhere('run.finishedAt BETWEEN :from AND :to')
            ->setParameter('statuses', [ImportRunStatus::Completed, ImportRunStatus::Partial, ImportRunStatus::Failed])
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->setParameter('to', $to, Types::DATETIME_IMMUTABLE)
            ->orderBy('run.finishedAt', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $runs;
    }
}
