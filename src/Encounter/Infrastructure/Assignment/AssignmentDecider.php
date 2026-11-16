<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Assignment;

use App\Encounter\Application\Assignment\AssignmentDecisionOutcome;
use App\Encounter\Application\Assignment\AssignmentState;
use App\Encounter\Application\Contract\AssignmentDeciderInterface;
use App\Encounter\Application\Import\EncounterSchemaHandlerRegistry;
use App\Encounter\Contract\Assignment\AssignmentCandidate;
use App\Encounter\Contract\Assignment\AssignmentKind;
use App\Encounter\Contract\Assignment\AssignmentRequest;
use App\Encounter\Contract\Assignment\AssignmentResult;
use App\Encounter\Contract\Assignment\EncounterAssignmentsInterface;
use App\Encounter\Contract\Assignment\EncounterHospitalLockInterface;
use App\Encounter\Contract\Import\EncounterSchemaHandlerInterface;
use App\Encounter\Domain\Enum\AssignmentDecisionAction;
use App\Encounter\Domain\Enum\PendingAssignmentStatus;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

/**
 * Manual decisions on assignment conflicts (#1307), in one transaction each:
 *
 * 1. take the encounter hospital lock and reload the pending record (`FOR UPDATE`); it must still be an
 *    open conflict (status `open`, kind ambiguous or contradictory) of the hospital;
 * 2. run the assignment again with the stored request;
 * 3. if the fresh result differs from the stored record or from what the reviewer saw, decide nothing: a
 *    fresh conflict or "no candidate" is stored on the record; a fresh unique result leaves the record as it
 *    is (the targeted retry follows with #1307's retries);
 * 4. otherwise assign (through the schema handler, which only attaches a record still without encounter;
 *    the pending record goes only after that succeeded) or defer, and keep the decision in the history.
 *
 * Import write, reprocessing and the matcher do not take the hospital lock before #1300, so a candidate can
 * be deleted in parallel: the foreign key then rolls the whole decision back.
 *
 * @psalm-suppress UnusedClass Wired as AssignmentDeciderInterface.
 */
final readonly class AssignmentDecider implements AssignmentDeciderInterface
{
    private const array CONFLICT_KINDS = [AssignmentKind::Ambiguous, AssignmentKind::Contradictory];

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private Connection $connection,
        private EncounterHospitalLockInterface $lock,
        private EncounterAssignmentsInterface $assignments,
        private EncounterSchemaHandlerRegistry $handlers,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function assign(int $hospitalId, int $pendingId, AssignmentState $seen, int $encounterId, User $user): AssignmentDecisionOutcome
    {
        return $this->decide($hospitalId, $pendingId, $seen, $user, AssignmentDecisionAction::Assigned, $encounterId);
    }

    #[\Override]
    public function defer(int $hospitalId, int $pendingId, AssignmentState $seen, User $user): AssignmentDecisionOutcome
    {
        return $this->decide($hospitalId, $pendingId, $seen, $user, AssignmentDecisionAction::Deferred, null);
    }

    private function decide(int $hospitalId, int $pendingId, AssignmentState $seen, User $user, AssignmentDecisionAction $action, ?int $encounterId): AssignmentDecisionOutcome
    {
        try {
            return $this->connection->transactional(function () use ($hospitalId, $pendingId, $seen, $user, $action, $encounterId): AssignmentDecisionOutcome {
                $this->lock->lock($hospitalId);

                $row = $this->pendingRow($hospitalId, $pendingId);
                if (null === $row) {
                    return AssignmentDecisionOutcome::NotOpen;
                }

                $storedKind = AssignmentKind::from($row['kind']);
                /** @var list<array{encounter_id: int, reason: string}> $storedCandidates */
                $storedCandidates = json_decode($row['candidates'], true, flags: \JSON_THROW_ON_ERROR);
                $stored = AssignmentState::stored($storedKind, $storedCandidates);

                $fresh = $this->assignments->find(new AssignmentRequest(
                    hospitalId: $hospitalId,
                    keyGeneration: (int) $row['key_generation'],
                    allocationId: null === $row['allocation_id'] ? null : (int) $row['allocation_id'],
                    caseKey: $row['case_key'],
                    at: null === $row['requested_at'] ? null : new \DateTimeImmutable($row['requested_at']),
                ));
                $freshState = AssignmentState::of($fresh);

                if (!$freshState->equals($stored)) {
                    if (AssignmentKind::Assigned !== $fresh->kind) {
                        $this->storeFreshState($pendingId, $fresh);
                    }

                    return AssignmentDecisionOutcome::Changed;
                }
                if (!$freshState->equals($seen)) {
                    return AssignmentDecisionOutcome::Changed;
                }

                if (AssignmentDecisionAction::Assigned === $action) {
                    if (null === $encounterId || !$stored->contains($encounterId)) {
                        return AssignmentDecisionOutcome::InvalidCandidate;
                    }

                    $handler = $this->handlers->find($row['exchange_schema']);
                    if (!$handler instanceof EncounterSchemaHandlerInterface || !$handler->attach($row['record_id'], $encounterId)) {
                        return AssignmentDecisionOutcome::RecordChanged;
                    }

                    $this->connection->executeStatement('DELETE FROM encounter_pending_assignment WHERE id = ?', [$pendingId]);
                } else {
                    $this->connection->executeStatement(
                        'UPDATE encounter_pending_assignment SET status = ?, seen_kind = ?, seen_candidates = ?, decided_by_id = ?, decided_at = ? WHERE id = ?',
                        [PendingAssignmentStatus::Deferred->value, $storedKind->value, $row['candidates'], $user->getId(), $this->clock->now(), $pendingId],
                        [4 => Types::DATETIME_IMMUTABLE],
                    );
                }

                $this->connection->executeStatement(
                    'INSERT INTO encounter_assignment_decision (hospital_id, import_id, exchange_schema, record_id, action, seen_kind, seen_candidates, encounter_id, decided_by_id, decided_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$hospitalId, (int) $row['import_id'], $row['exchange_schema'], $row['record_id'], $action->value, $storedKind->value, $row['candidates'], $encounterId, $user->getId(), $this->clock->now()],
                    [9 => Types::DATETIME_IMMUTABLE],
                );

                return AssignmentDecisionOutcome::Decided;
            });
        } catch (ForeignKeyConstraintViolationException) {
            return AssignmentDecisionOutcome::EncounterGone;
        }
    }

    /**
     * The pending record if it is still an open conflict of the hospital, locked for this transaction.
     *
     * @return array{key_generation: int|string, import_id: int|string, exchange_schema: string, record_id: string, kind: string, allocation_id: int|string|null, case_key: ?string, requested_at: ?string, candidates: string}|null
     */
    private function pendingRow(int $hospitalId, int $pendingId): ?array
    {
        /** @var array{key_generation: int|string, import_id: int|string, exchange_schema: string, record_id: string, kind: string, allocation_id: int|string|null, case_key: ?string, requested_at: ?string, candidates: string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT key_generation, import_id, exchange_schema, record_id, kind, allocation_id, case_key, requested_at, candidates
             FROM encounter_pending_assignment
             WHERE id = ? AND hospital_id = ? AND status = ? AND kind IN (?, ?)
             FOR UPDATE',
            [$pendingId, $hospitalId, PendingAssignmentStatus::Open->value, ...array_map(static fn (AssignmentKind $kind): string => $kind->value, self::CONFLICT_KINDS)],
        );

        return false === $row ? null : $row;
    }

    private function storeFreshState(int $pendingId, AssignmentResult $fresh): void
    {
        $this->connection->executeStatement(
            'UPDATE encounter_pending_assignment SET kind = ?, candidates = ? WHERE id = ?',
            [
                $fresh->kind->value,
                json_encode(array_map(static fn (AssignmentCandidate $candidate): array => ['encounter_id' => $candidate->encounterId, 'reason' => $candidate->reason->value], $fresh->candidates), \JSON_THROW_ON_ERROR),
                $pendingId,
            ],
        );
    }
}
