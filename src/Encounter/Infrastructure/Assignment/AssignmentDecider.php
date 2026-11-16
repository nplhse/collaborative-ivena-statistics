<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Assignment;

use App\Encounter\Application\Assignment\AssignmentDecisionOutcome;
use App\Encounter\Application\Assignment\AssignmentState;
use App\Encounter\Application\Contract\AssignmentDeciderInterface;
use App\Encounter\Contract\Assignment\AssignmentKind;
use App\Encounter\Contract\Assignment\EncounterHospitalLockInterface;
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
 *    is and is reported as `UniqueCandidate`, so that the caller starts the targeted retry after the commit;
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
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private Connection $connection,
        private EncounterHospitalLockInterface $lock,
        private PendingAssignmentRows $rows,
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

                $row = $this->rows->lockOne($hospitalId, $pendingId);
                if (null === $row || !$this->rows->isOpenConflict($row)) {
                    return AssignmentDecisionOutcome::NotOpen;
                }

                $stored = $this->rows->stored($row);
                $fresh = $this->rows->findAgain($row);
                $freshState = AssignmentState::of($fresh);

                if (AssignmentKind::Assigned === $fresh->kind) {
                    return AssignmentDecisionOutcome::UniqueCandidate;
                }
                if (!$freshState->equals($stored)) {
                    $this->rows->reopen($pendingId, $fresh);

                    return AssignmentDecisionOutcome::Changed;
                }
                if (!$freshState->equals($seen)) {
                    return AssignmentDecisionOutcome::Changed;
                }

                if (AssignmentDecisionAction::Assigned === $action) {
                    if (null === $encounterId || !$stored->contains($encounterId)) {
                        return AssignmentDecisionOutcome::InvalidCandidate;
                    }
                    if (!$this->rows->attach($row, $encounterId)) {
                        return AssignmentDecisionOutcome::RecordChanged;
                    }
                } else {
                    $this->connection->executeStatement(
                        'UPDATE encounter_pending_assignment SET status = ?, seen_kind = ?, seen_candidates = ?, decided_by_id = ?, decided_at = ? WHERE id = ?',
                        [PendingAssignmentStatus::Deferred->value, $row['kind'], $row['candidates'], $user->getId(), $this->clock->now(), $pendingId],
                        [4 => Types::DATETIME_IMMUTABLE],
                    );
                }

                $this->connection->executeStatement(
                    'INSERT INTO encounter_assignment_decision (hospital_id, import_id, exchange_schema, record_id, action, seen_kind, seen_candidates, encounter_id, decided_by_id, decided_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$hospitalId, (int) $row['import_id'], $row['exchange_schema'], $row['record_id'], $action->value, $row['kind'], $row['candidates'], $encounterId, $user->getId(), $this->clock->now()],
                    [9 => Types::DATETIME_IMMUTABLE],
                );

                return AssignmentDecisionOutcome::Decided;
            });
        } catch (ForeignKeyConstraintViolationException) {
            return AssignmentDecisionOutcome::EncounterGone;
        }
    }
}
