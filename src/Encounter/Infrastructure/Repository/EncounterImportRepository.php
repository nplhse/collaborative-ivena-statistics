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

    /**
     * Ids of the hospital's encounter imports uploaded with a key generation before the given one,
     * ascending (key change with the option "delete", ADR 027).
     *
     * @return list<int>
     */
    public function importIdsBeforeGeneration(int $hospitalId, int $keyGeneration): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT i.id FROM import i INNER JOIN encounter_import ei ON ei.import_id = i.id WHERE i.hospital_id = ? AND ei.key_generation < ? ORDER BY i.id',
            [$hospitalId, $keyGeneration],
        );

        return array_map(intval(...), $ids);
    }
}
