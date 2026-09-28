<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Domain\Entity\ClosureInterval;
use App\Import\Domain\Entity\Import;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClosureInterval>
 */
final class ClosureIntervalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClosureInterval::class);
    }

    public function deleteByImport(Import $import): int
    {
        $deleted = $this->createQueryBuilder('c')
            ->delete()
            ->where('IDENTITY(c.import) = :importId')
            ->setParameter('importId', $import->getId(), Types::INTEGER)
            ->getQuery()
            ->execute();

        return (int) $deleted;
    }
}
