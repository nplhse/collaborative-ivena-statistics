<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Repository;

use App\Encounter\Application\Contract\EncounterImportReaderInterface;
use App\Encounter\Domain\Entity\EncounterImport;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EncounterImport>
 */
final class EncounterImportRepository extends ServiceEntityRepository implements EncounterImportReaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EncounterImport::class);
    }

    #[\Override]
    public function findForImportId(int $importId): ?EncounterImport
    {
        return $this->createQueryBuilder('ei')
            ->addSelect('i', 'h')
            ->innerJoin('ei.import', 'i')
            ->innerJoin('i.hospital', 'h')
            ->where('i.id = :importId')
            ->andWhere('i.type = :type')
            ->setParameter('importId', $importId)
            ->setParameter('type', ImportType::ENCOUNTER)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Imports with a pending or running run are left for the next day, so a run never loses its file.
     */
    #[\Override]
    public function findWithKeptSourceFileUploadedBefore(\DateTimeImmutable $cutoff): array
    {
        /** @var list<EncounterImport> $imports */
        $imports = $this->createQueryBuilder('ei')
            ->addSelect('i')
            ->innerJoin('ei.import', 'i')
            ->where('i.type = :type')
            ->andWhere('ei.sourceFileDeleted = false')
            ->andWhere('i.createdAt < :cutoff')
            ->andWhere('i.status NOT IN (:active)')
            ->setParameter('type', ImportType::ENCOUNTER)
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->setParameter('active', [ImportStatus::PENDING->value, ImportStatus::RUNNING->value])
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $imports;
    }

    public function findForImport(Import $import): ?EncounterImport
    {
        return $this->findOneBy(['import' => $import]);
    }
}
