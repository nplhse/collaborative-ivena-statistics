<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Infrastructure\Repository;

use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Enum\EncounterInsertOutcome;
use Cois\Plugin\EdVisit\Domain\Entity\EdVisit;
use Cois\Plugin\EdVisit\Infrastructure\Persistence\EncounterInsertStatement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Encounter>
 */
final class EncounterRepository extends ServiceEntityRepository
{
    private const string SAVEPOINT = 'ed_visit_insert';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Encounter::class);
    }

    /**
     * Inserts a new ED visit with its encounter, or skips it when the hospital already has a visit with the same
     * fingerprint in the same key generation (ADR 029). A known visit is never changed.
     *
     * The inserts run directly on the connection (inside the caller's transaction, if any); the given object is
     * not managed by the EntityManager afterwards.
     */
    public function insertIfNew(EdVisit $visit): EncounterInsertOutcome
    {
        return null === $this->insertIfNewReturningId($visit) ? EncounterInsertOutcome::Duplicate : EncounterInsertOutcome::Inserted;
    }

    /**
     * Like insertIfNew(), but returns the id of the new visit's encounter (which identifies the visit), or null
     * for a duplicate. Encounter and visit are written in one savepoint; for a duplicate (also one a parallel
     * import committed first) the savepoint is rolled back, so no encounter stays without its visit (#1300).
     */
    public function insertIfNewReturningId(EdVisit $visit): ?int
    {
        $em = $this->getEntityManager();
        $connection = $em->getConnection();
        $ownTransaction = !$connection->isTransactionActive();
        if ($ownTransaction) {
            $connection->beginTransaction();
        }

        try {
            $connection->createSavepoint(self::SAVEPOINT);
            $encounter = EncounterInsertStatement::forEncounter($em, $visit->getEncounter());
            $encounterId = (int) $connection->executeQuery($encounter->sql, $encounter->params, $encounter->types)->fetchOne();
            $statement = EncounterInsertStatement::forVisit($em, $visit, $encounterId);
            $inserted = false !== $connection->executeQuery($statement->sql, $statement->params, $statement->types)->fetchOne();
            if ($inserted) {
                $connection->releaseSavepoint(self::SAVEPOINT);
            } else {
                // A duplicate (also one a parallel import committed first): the encounter goes with the savepoint.
                $connection->rollbackSavepoint(self::SAVEPOINT);
            }

            if ($ownTransaction) {
                $connection->commit();
            }

            return $inserted ? $encounterId : null;
        } catch (\Throwable $exception) {
            if ($ownTransaction) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Gives a known visit of a reprocessed import the values of the file (#1300): the ED fields and the base data
     * of its encounter (case key, anchor, age, sex). Identity, uid, IVENA assignment and ED matching history stay.
     */
    public function updateKnown(EdVisit $visit, int $encounterId): void
    {
        $em = $this->getEntityManager();
        $connection = $em->getConnection();
        $statement = EncounterInsertStatement::forVisitUpdate($em, $visit, $encounterId);
        $connection->executeStatement($statement->sql, $statement->params, $statement->types);

        $encounter = $visit->getEncounter();
        $connection->executeStatement(
            'UPDATE encounter SET case_key = ?, anchor_at = ?, age = ?, sex = ? WHERE id = ?',
            [$encounter->getCaseKey(), $encounter->getAnchorAt(), $encounter->getAge(), $encounter->getSex()->value, $encounterId],
            [Types::STRING, Types::DATETIME_IMMUTABLE, Types::SMALLINT, Types::STRING, Types::INTEGER],
        );
    }

    /**
     * Earlier visits of the hospital and key generation with the same case key and a time anchor
     * within $windowMinutes of the given visit's anchor (suspected duplicates, ADR 029).
     *
     * @return list<array{id: int, anchor_source: string, difference_minutes: int}>
     */
    public function findSuspectedDuplicatesOf(int $encounterId, int $windowMinutes): array
    {
        /** @var list<array{id: int|string, anchor_source: string, difference_minutes: int|string|float}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
SELECT other.id, other_visit.anchor_source, ABS(EXTRACT(EPOCH FROM (other.anchor_at - visit.anchor_at)) / 60) AS difference_minutes
FROM encounter visit
JOIN encounter other
  ON other.hospital_id = visit.hospital_id
 AND other.key_generation = visit.key_generation
 AND other.case_key = visit.case_key
 AND other.id <> visit.id
 AND other.anchor_at BETWEEN visit.anchor_at - make_interval(mins => :window) AND visit.anchor_at + make_interval(mins => :window)
JOIN ed_visit other_visit ON other_visit.encounter_id = other.id
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
     * Removes the ED visits created by one import (ADR 029). Their encounters stay, with the effective IVENA
     * assignment and the records of other schemas, until the cleanup of encounters without records (#1300).
     * The ED matching history, suspected duplicates and validation items of these visits are removed by the
     * caller first.
     */
    public function deleteByImportId(int $importId): int
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM ed_visit WHERE import_id = ?',
            [$importId],
            [Types::INTEGER],
        );
    }

    public function countByImportId(int $importId): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ed_visit WHERE import_id = ?',
            [$importId],
            [Types::INTEGER],
        );
    }
}
