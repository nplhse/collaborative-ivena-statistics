<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Repository;

use App\Encounter\Application\Contract\EncounterImportReaderInterface;
use App\Encounter\Domain\Entity\EncounterImport;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    public function findForImport(Import $import): ?EncounterImport
    {
        return $this->findOneBy(['import' => $import]);
    }

    /**
     * Ids of the hospital's encounter imports uploaded with a key generation before the given one,
     * ascending (key change with the option "delete", ADR 026).
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
