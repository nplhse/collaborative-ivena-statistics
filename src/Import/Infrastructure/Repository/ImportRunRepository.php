<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Repository;

use App\Import\Domain\Entity\ImportRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
