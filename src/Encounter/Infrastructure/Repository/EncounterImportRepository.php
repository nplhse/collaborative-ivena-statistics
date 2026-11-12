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

    #[\Override]
    public function findForHospitals(array $hospitals, int $limit = 200): array
    {
        if ([] === $hospitals) {
            return [];
        }

        /** @var list<EncounterImport> $imports */
        $imports = $this->createQueryBuilder('ei')
            ->addSelect('i', 'h')
            ->innerJoin('ei.import', 'i')
            ->innerJoin('i.hospital', 'h')
            ->where('i.hospital IN (:hospitals)')
            ->setParameter('hospitals', $hospitals)
            ->orderBy('i.createdAt', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $imports;
    }

    public function findForImport(Import $import): ?EncounterImport
    {
        return $this->findOneBy(['import' => $import]);
    }
}
