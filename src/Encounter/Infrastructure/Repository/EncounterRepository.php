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
        return null === $this->insertIfNewReturningId($encounter) ? EncounterInsertOutcome::Duplicate : EncounterInsertOutcome::Inserted;
    }

    /**
     * Like insertIfNew(), but returns the id of the inserted visit, or null for a duplicate.
     */
    public function insertIfNewReturningId(Encounter $encounter): ?int
    {
        $statement = EncounterInsertStatement::for($this->getEntityManager(), $encounter);

        $insertedId = $this->getEntityManager()->getConnection()
            ->executeQuery($statement->sql, $statement->params, $statement->types)
            ->fetchOne();

        return false === $insertedId ? null : (int) $insertedId;
    }

    /**
     * Earlier visits of the hospital and key generation with the same case key and a time anchor
     * within $windowMinutes of the given visit's anchor (suspected duplicates, ADR 028).
     *
     * @return list<array{id: int, anchor_source: string, difference_minutes: int}>
     */
    public function findSuspectedDuplicatesOf(int $encounterId, int $windowMinutes): array
    {
        /** @var list<array{id: int|string, anchor_source: string, difference_minutes: int|string|float}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
SELECT other.id, other.anchor_source, ABS(EXTRACT(EPOCH FROM (other.anchor_at - visit.anchor_at)) / 60) AS difference_minutes
FROM encounter visit
JOIN encounter other
  ON other.hospital_id = visit.hospital_id
 AND other.key_generation = visit.key_generation
 AND other.case_key = visit.case_key
 AND other.id <> visit.id
 AND other.anchor_at BETWEEN visit.anchor_at - make_interval(mins => :window) AND visit.anchor_at + make_interval(mins => :window)
WHERE visit.id = :id
ORDER BY other.id
SQL,
            ['id' => $encounterId, 'window' => $windowMinutes],
            ['id' => Types::INTEGER, 'window' => Types::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'anchor_source' => $row['anchor_source'],
            'difference_minutes' => (int) round((float) $row['difference_minutes']),
        ], $rows);
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
