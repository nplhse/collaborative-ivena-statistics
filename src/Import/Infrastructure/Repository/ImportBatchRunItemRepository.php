<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Repository;

use App\Import\Domain\Entity\ImportBatchRunItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportBatchRunItem>
 */
final class ImportBatchRunItemRepository extends ServiceEntityRepository
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportBatchRunItem::class);
    }

    public function deleteByImportId(int $importId): void
    {
        $this->createQueryBuilder('item')
            ->delete()
            ->where('item.importId = :importId')
            ->setParameter('importId', $importId)
            ->getQuery()
            ->execute();
    }
}
