<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Assignment\AssignmentConflict;
use App\Encounter\Application\Assignment\AssignmentConflictCandidate;
use App\Encounter\Application\Assignment\DeferredAssignment;
use App\Encounter\Application\Contract\AssignmentConflictReaderInterface;
use App\Encounter\Contract\Assignment\AssignmentKind;
use App\Encounter\Contract\Assignment\CandidateReason;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Open assignment conflicts (`encounter_pending_assignment` with status `open` and kind ambiguous or
 * contradictory) and deferred records (#1307) of a hospital, with the visit uid and anchor of each candidate.
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
            "SELECT h.id, h.name FROM hospital h WHERE EXISTS (SELECT 1 FROM encounter_pending_assignment p WHERE p.hospital_id = h.id AND p.status = 'open' AND p.kind IN (?)) ORDER BY h.name, h.id",
            [self::CONFLICT_KINDS],
            [ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['name']], $rows);
    }

    #[\Override]
    public function countOf(int $hospitalId): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM encounter_pending_assignment WHERE hospital_id = ? AND status = 'open' AND kind IN (?)",
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
             WHERE p.hospital_id = ? AND p.status = \'open\' AND p.kind IN (?)
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT ?',
            [$hospitalId, self::CONFLICT_KINDS, $limit],
            [1 => ArrayParameterType::STRING, 2 => ParameterType::INTEGER],
        );

        $candidates = $this->candidates(array_column($rows, 'candidates', 'id'));

        return array_map(static fn (array $row): AssignmentConflict => new AssignmentConflict(
            id: (int) $row['id'],
            schema: $row['exchange_schema'],
            recordId: $row['record_id'],
            kind: AssignmentKind::from($row['kind']),
            importId: (int) $row['import_id'],
            importName: $row['import_name'],
            createdAt: new \DateTimeImmutable($row['created_at']),
            candidates: $candidates[(int) $row['id']],
        ), $rows);
    }

    #[\Override]
    public function countDeferredOf(int $hospitalId): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM encounter_pending_assignment WHERE hospital_id = ? AND status = 'deferred'",
            [$hospitalId],
        );
    }

    #[\Override]
    public function deferredOf(int $hospitalId, int $limit): array
    {
        /** @var list<array{id: int|string, exchange_schema: string, record_id: string, seen_kind: string, import_id: int|string, import_name: string, seen_candidates: string, decided_by: ?string, decided_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.exchange_schema, p.record_id, p.seen_kind, p.import_id, i.name AS import_name, p.seen_candidates, u.username AS decided_by, p.decided_at
             FROM encounter_pending_assignment p
             INNER JOIN import i ON i.id = p.import_id
             LEFT JOIN "user" u ON u.id = p.decided_by_id
             WHERE p.hospital_id = ? AND p.status = \'deferred\'
             ORDER BY p.decided_at DESC, p.id DESC
             LIMIT ?',
            [$hospitalId, $limit],
            [1 => ParameterType::INTEGER],
        );

        $candidates = $this->candidates(array_column($rows, 'seen_candidates', 'id'));

        return array_map(static fn (array $row): DeferredAssignment => new DeferredAssignment(
            id: (int) $row['id'],
            schema: $row['exchange_schema'],
            recordId: $row['record_id'],
            kind: AssignmentKind::from($row['seen_kind']),
            importId: (int) $row['import_id'],
            importName: $row['import_name'],
            candidates: $candidates[(int) $row['id']],
            decidedBy: $row['decided_by'],
            decidedAt: new \DateTimeImmutable($row['decided_at']),
        ), $rows);
    }

    /**
     * Candidates of each row with the visit uid and anchor of their encounter (null when it is gone).
     *
     * @param array<int|string, string> $json candidates JSON by row id
     *
     * @return array<int, list<AssignmentConflictCandidate>>
     */
    private function candidates(array $json): array
    {
        $decoded = [];
        $encounterIds = [];
        foreach ($json as $id => $candidatesJson) {
            /** @var list<array{encounter_id: int, reason: string}> $candidates */
            $candidates = json_decode($candidatesJson, true, flags: \JSON_THROW_ON_ERROR);
            $decoded[(int) $id] = $candidates;
            foreach ($candidates as $candidate) {
                $encounterIds[$candidate['encounter_id']] = $candidate['encounter_id'];
            }
        }

        $visits = $this->visits(array_values($encounterIds));

        return array_map(static fn (array $candidates): array => array_map(static fn (array $candidate): AssignmentConflictCandidate => new AssignmentConflictCandidate(
            encounterId: $candidate['encounter_id'],
            reason: CandidateReason::from($candidate['reason']),
            visitUid: $visits[$candidate['encounter_id']]['visit_uid'] ?? null,
            anchorAt: isset($visits[$candidate['encounter_id']]) ? new \DateTimeImmutable($visits[$candidate['encounter_id']]['anchor_at']) : null,
        ), $candidates), $decoded);
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
