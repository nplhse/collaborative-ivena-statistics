<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Repository;

use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportReject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportReject>
 */
final class ImportRejectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportReject::class);
    }

    public function deleteByImport(Import $import): int
    {
        return $this->createQueryBuilder('r')
            ->delete()
            ->where('r.import = :import')
            ->setParameter('import', $import)
            ->getQuery()
            ->execute();
    }
}
