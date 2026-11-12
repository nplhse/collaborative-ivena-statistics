<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Repository;

use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Enum\EncounterInsertOutcome;
use App\Encounter\Infrastructure\Persistence\EncounterInsertStatement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Encounter>
 */
final class EncounterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Encounter::class);
    }

    /**
     * Inserts a new visit, or skips it when the hospital already has a visit with the same fingerprint
     * in the same key generation (ADR 028). A known visit is never changed.
     *
     * The insert runs directly on the connection (inside the caller's transaction, if any); the
     * given object is not managed by the EntityManager afterwards.
     */
    public function insertIfNew(Encounter $encounter): EncounterInsertOutcome
    {
        $statement = EncounterInsertStatement::for($this->getEntityManager(), $encounter);

        $insertedId = $this->getEntityManager()->getConnection()
            ->executeQuery($statement->sql, $statement->params, $statement->types)
            ->fetchOne();

        return false === $insertedId ? EncounterInsertOutcome::Duplicate : EncounterInsertOutcome::Inserted;
    }

    /**
     * Removes the visits created by one import. Links and review decisions of these visits reference
     * the encounter with ON DELETE CASCADE and go with them (ADR 028, ADR 029).
     */
    public function deleteByImportId(int $importId): int
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM encounter WHERE import_id = ?',
            [$importId],
            [Types::INTEGER],
        );
    }

    public function countByImportId(int $importId): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM encounter WHERE import_id = ?',
            [$importId],
            [Types::INTEGER],
        );
    }
}
