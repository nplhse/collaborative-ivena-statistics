<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Assignment;

use App\Encounter\Application\Assignment\AssignmentState;
use App\Encounter\Application\Import\EncounterSchemaHandlerRegistry;
use App\Encounter\Contract\Assignment\AssignmentCandidate;
use App\Encounter\Contract\Assignment\AssignmentKind;
use App\Encounter\Contract\Assignment\AssignmentRequest;
use App\Encounter\Contract\Assignment\AssignmentResult;
use App\Encounter\Contract\Assignment\EncounterAssignmentsInterface;
use App\Encounter\Contract\Import\EncounterSchemaHandlerInterface;
use App\Encounter\Domain\Enum\PendingAssignmentStatus;
use Doctrine\DBAL\Connection;

/**
 * What the manual decisions and the retries (#1307) do with a row of `encounter_pending_assignment`, always
 * inside a transaction that holds the encounter hospital lock: run the assignment again with the stored
 * request, attach the record through its schema's handler, or store a fresh unassigned state.
 *
 * @phpstan-type PendingRow array{id: int|string, hospital_id: int|string, key_generation: int|string, import_id: int|string, exchange_schema: string, record_id: string, status: string, kind: string, allocation_id: int|string|null, case_key: ?string, requested_at: ?string, candidates: string, seen_kind: ?string, seen_candidates: ?string}
 */
final readonly class PendingAssignmentRows
{
    public const string COLUMNS = 'id, hospital_id, key_generation, import_id, exchange_schema, record_id, status, kind, allocation_id, case_key, requested_at, candidates, seen_kind, seen_candidates';

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private Connection $connection,
        private EncounterAssignmentsInterface $assignments,
        private EncounterSchemaHandlerRegistry $handlers,
    ) {
    }

    /**
     * One pending record of the hospital, locked for this transaction; null when it is gone.
     *
     * @return PendingRow|null
     */
    public function lockOne(int $hospitalId, int $pendingId): ?array
    {
        /** @var PendingRow|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM encounter_pending_assignment WHERE id = ? AND hospital_id = ? FOR UPDATE',
            [$pendingId, $hospitalId],
        );

        return false === $row ? null : $row;
    }

    /**
     * The records a regular retry looks at, locked for this transaction: of the hospital and key generation,
     * open without candidate or deferred. Open conflicts wait for review.
     *
     * @return list<PendingRow>
     */
    public function lockRetryable(int $hospitalId, int $keyGeneration): array
    {
        /** @var list<PendingRow> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM encounter_pending_assignment
             WHERE hospital_id = ? AND key_generation = ? AND ((status = ? AND kind = ?) OR status = ?)
             ORDER BY id
             FOR UPDATE',
            [$hospitalId, $keyGeneration, PendingAssignmentStatus::Open->value, AssignmentKind::NoCandidate->value, PendingAssignmentStatus::Deferred->value],
        );

        return $rows;
    }

    /**
     * @param PendingRow $row
     */
    public function isOpenConflict(array $row): bool
    {
        return PendingAssignmentStatus::Open->value === $row['status']
            && \in_array($row['kind'], [AssignmentKind::Ambiguous->value, AssignmentKind::Contradictory->value], true);
    }

    /**
     * Runs the assignment again with the request the record was kept with.
     *
     * @param PendingRow $row
     */
    public function findAgain(array $row): AssignmentResult
    {
        return $this->assignments->find(new AssignmentRequest(
            hospitalId: (int) $row['hospital_id'],
            keyGeneration: (int) $row['key_generation'],
            allocationId: null === $row['allocation_id'] ? null : (int) $row['allocation_id'],
            caseKey: $row['case_key'],
            at: null === $row['requested_at'] ? null : new \DateTimeImmutable($row['requested_at']),
        ));
    }

    /**
     * @param PendingRow $row
     */
    public function stored(array $row): AssignmentState
    {
        return AssignmentState::stored(AssignmentKind::from($row['kind']), $this->decode($row['candidates']));
    }

    /**
     * The conflict a reviewer deferred; null for an open record.
     *
     * @param PendingRow $row
     */
    public function seen(array $row): ?AssignmentState
    {
        return null === $row['seen_kind'] || null === $row['seen_candidates']
            ? null
            : AssignmentState::stored(AssignmentKind::from($row['seen_kind']), $this->decode($row['seen_candidates']));
    }

    /**
     * Attaches the record through its schema's handler; the pending row goes only if that succeeded. False
     * when the record is gone or already attached, or its schema is no longer registered. A foreign key
     * violation (encounter deleted meanwhile) is passed on.
     *
     * @param PendingRow $row
     */
    public function attach(array $row, int $encounterId): bool
    {
        $handler = $this->handlers->find($row['exchange_schema']);
        if (!$handler instanceof EncounterSchemaHandlerInterface || !$handler->attach($row['record_id'], $encounterId)) {
            return false;
        }

        $this->connection->executeStatement('DELETE FROM encounter_pending_assignment WHERE id = ?', [$row['id']]);

        return true;
    }

    /**
     * Stores a fresh unassigned result (no candidate, ambiguous or contradictory) as an open record. A
     * deferred record becomes open again; its decision stays in `encounter_assignment_decision`.
     */
    public function reopen(int $pendingId, AssignmentResult $fresh): void
    {
        if (AssignmentKind::Assigned === $fresh->kind) {
            throw new \LogicException('An assigned result is attached, not stored as pending.');
        }

        $this->connection->executeStatement(
            'UPDATE encounter_pending_assignment
             SET status = ?, kind = ?, candidates = ?, seen_kind = NULL, seen_candidates = NULL, decided_by_id = NULL, decided_at = NULL
             WHERE id = ?',
            [
                PendingAssignmentStatus::Open->value,
                $fresh->kind->value,
                json_encode(array_map(static fn (AssignmentCandidate $candidate): array => ['encounter_id' => $candidate->encounterId, 'reason' => $candidate->reason->value], $fresh->candidates), \JSON_THROW_ON_ERROR),
                $pendingId,
            ],
        );
    }

    /**
     * @return list<array{encounter_id: int, reason: string}>
     */
    private function decode(string $json): array
    {
        /** @var list<array{encounter_id: int, reason: string}> $candidates */
        $candidates = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $candidates;
    }
}
