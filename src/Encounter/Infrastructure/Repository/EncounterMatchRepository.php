<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Repository;

use App\Encounter\Application\Matching\MatchPlanner;
use App\Encounter\Application\Matching\PlannedMatch;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Enum\MatchStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Writes the matcher's rows (ADR 030). Automatic rows go straight to the connection inside the caller's
 * transaction; manual decisions go through the entity (#1054).
 *
 * @extends ServiceEntityRepository<EncounterMatch>
 */
final class EncounterMatchRepository extends ServiceEntityRepository
{
    /** First key of the transaction-level advisory lock; the second is the hospital id. */
    private const int LOCK_NAMESPACE = 1053;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EncounterMatch::class);
    }

    /**
     * Serialises matcher runs and decisions of one hospital until the current transaction ends. The
     * review (#1054) takes the same lock before it writes a decision.
     */
    public function lockHospital(int $hospitalId): void
    {
        $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock(:namespace, :hospital)',
            ['namespace' => self::LOCK_NAMESPACE, 'hospital' => $hospitalId],
            ['namespace' => Types::INTEGER, 'hospital' => Types::INTEGER],
        );
    }

    /**
     * Removes the open proposals of the given allocations; links and decisions stay.
     *
     * @param list<int> $allocationIds
     */
    public function deleteProposalsOfAllocations(array $allocationIds): void
    {
        if ([] === $allocationIds) {
            return;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM encounter_match WHERE status = :status AND allocation_id = ANY(CAST(:allocations AS INT[]))',
            ['status' => MatchStatus::Proposed->value, 'allocations' => '{'.implode(',', $allocationIds).'}'],
            ['status' => Types::STRING, 'allocations' => Types::STRING],
        );
    }

    /**
     * Removes proposals of the hospital whose allocation or visit has an effective link.
     */
    public function deleteProposalsTouchingLinks(int $hospitalId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
DELETE FROM encounter_match proposal
WHERE proposal.hospital_id = :hospital
  AND proposal.status = :proposed
  AND EXISTS (
      SELECT 1 FROM encounter_match link
      WHERE link.status = ANY(CAST(:effective AS TEXT[]))
        AND (link.allocation_id = proposal.allocation_id OR link.encounter_id = proposal.encounter_id)
  )
SQL,
            ['hospital' => $hospitalId, 'proposed' => MatchStatus::Proposed->value, 'effective' => '{'.implode(',', array_map(static fn (MatchStatus $s): string => $s->value, MatchStatus::effective())).'}'],
            ['hospital' => Types::INTEGER, 'proposed' => Types::STRING, 'effective' => Types::STRING],
        );
    }

    /**
     * @param list<PlannedMatch> $matches
     */
    public function insertPlanned(int $hospitalId, array $matches, \DateTimeImmutable $now): void
    {
        $connection = $this->getEntityManager()->getConnection();
        foreach ($matches as $match) {
            $connection->insert('encounter_match', [
                'hospital_id' => $hospitalId,
                'allocation_id' => $match->allocationId,
                'encounter_id' => $match->encounterId,
                'status' => $match->status->value,
                'conflict' => $match->conflict,
                'score' => $match->score,
                'points' => $match->points,
                'contributions' => json_encode($match->contributions, \JSON_THROW_ON_ERROR),
                'pass' => $match->pass,
                'matcher_version' => MatchPlanner::VERSION,
                'time_anchor' => $match->timeAnchor->value,
                'time_difference_minutes' => $match->timeDifferenceMinutes,
                'created_at' => $now,
            ], [
                'hospital_id' => Types::INTEGER,
                'allocation_id' => Types::INTEGER,
                'encounter_id' => Types::INTEGER,
                'status' => Types::STRING,
                'conflict' => Types::BOOLEAN,
                'score' => Types::FLOAT,
                'points' => Types::SMALLINT,
                'contributions' => Types::STRING,
                'pass' => Types::SMALLINT,
                'matcher_version' => Types::STRING,
                'time_anchor' => Types::STRING,
                'time_difference_minutes' => Types::INTEGER,
                'created_at' => Types::DATETIME_IMMUTABLE,
            ]);
        }
    }

    /**
     * Deletes every row of the allocations or visits of an import and returns their number (import
     * deletion and reprocess). The foreign keys would cascade anyway; deleting here makes the count visible.
     */
    public function deleteForImport(int $importId): int
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
DELETE FROM encounter_match m
WHERE m.allocation_id IN (SELECT id FROM allocation WHERE import_id = :import)
   OR m.encounter_id IN (SELECT id FROM encounter WHERE import_id = :import)
SQL,
            ['import' => $importId],
            ['import' => Types::INTEGER],
        );
    }
}
