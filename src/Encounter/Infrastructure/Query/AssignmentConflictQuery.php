<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Assignment\AssignmentConflict;
use App\Encounter\Application\Assignment\AssignmentConflictCandidate;
use App\Encounter\Application\Contract\AssignmentConflictReaderInterface;
use App\Encounter\Contract\Assignment\AssignmentKind;
use App\Encounter\Contract\Assignment\CandidateReason;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Open assignment conflicts (`encounter_pending_assignment` with kind ambiguous or contradictory) of a
 * hospital with the visit uid and anchor of each candidate, in two queries.
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of AssignmentConflictReaderInterface.
 */
final readonly class AssignmentConflictQuery implements AssignmentConflictReaderInterface
{
    private const array CONFLICT_KINDS = ['ambiguous', 'contradictory'];

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function hospitalsWithConflicts(): array
    {
        /** @var list<array{id: int|string, name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT h.id, h.name FROM hospital h WHERE EXISTS (SELECT 1 FROM encounter_pending_assignment p WHERE p.hospital_id = h.id AND p.kind IN (?)) ORDER BY h.name, h.id',
            [self::CONFLICT_KINDS],
            [ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['name']], $rows);
    }

    #[\Override]
    public function countOf(int $hospitalId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM encounter_pending_assignment WHERE hospital_id = ? AND kind IN (?)',
            [$hospitalId, self::CONFLICT_KINDS],
            [1 => ArrayParameterType::STRING],
        );
    }

    #[\Override]
    public function conflictsOf(int $hospitalId, int $limit): array
    {
        /** @var list<array{id: int|string, exchange_schema: string, record_id: string, kind: string, import_id: int|string, import_name: string, created_at: string, candidates: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.exchange_schema, p.record_id, p.kind, p.import_id, i.name AS import_name, p.created_at, p.candidates
             FROM encounter_pending_assignment p INNER JOIN import i ON i.id = p.import_id
             WHERE p.hospital_id = ? AND p.kind IN (?)
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT ?',
            [$hospitalId, self::CONFLICT_KINDS, $limit],
            [1 => ArrayParameterType::STRING, 2 => ParameterType::INTEGER],
        );

        $candidatesByRow = [];
        $encounterIds = [];
        foreach ($rows as $row) {
            /** @var list<array{encounter_id: int, reason: string}> $candidates */
            $candidates = json_decode($row['candidates'], true, flags: \JSON_THROW_ON_ERROR);
            $candidatesByRow[(int) $row['id']] = $candidates;
            foreach ($candidates as $candidate) {
                $encounterIds[$candidate['encounter_id']] = $candidate['encounter_id'];
            }
        }

        $visits = $this->visits(array_values($encounterIds));

        return array_map(static fn (array $row): AssignmentConflict => new AssignmentConflict(
            id: (int) $row['id'],
            schema: $row['exchange_schema'],
            recordId: $row['record_id'],
            kind: AssignmentKind::from($row['kind']),
            importId: (int) $row['import_id'],
            importName: $row['import_name'],
            createdAt: new \DateTimeImmutable($row['created_at']),
            candidates: array_map(static fn (array $candidate): AssignmentConflictCandidate => new AssignmentConflictCandidate(
                encounterId: $candidate['encounter_id'],
                reason: CandidateReason::from($candidate['reason']),
                visitUid: $visits[$candidate['encounter_id']]['visit_uid'] ?? null,
                anchorAt: isset($visits[$candidate['encounter_id']]) ? new \DateTimeImmutable($visits[$candidate['encounter_id']]['anchor_at']) : null,
            ), $candidatesByRow[(int) $row['id']]),
        ), $rows);
    }

    /**
     * @param list<int> $encounterIds
     *
     * @return array<int, array{visit_uid: string, anchor_at: string}>
     */
    private function visits(array $encounterIds): array
    {
        if ([] === $encounterIds) {
            return [];
        }

        /** @var list<array{id: int|string, visit_uid: string, anchor_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, visit_uid, anchor_at FROM encounter WHERE id IN (?)',
            [$encounterIds],
            [ArrayParameterType::INTEGER],
        );

        $visits = [];
        foreach ($rows as $row) {
            $visits[(int) $row['id']] = ['visit_uid' => $row['visit_uid'], 'anchor_at' => $row['anchor_at']];
        }

        return $visits;
    }
}
