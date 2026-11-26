<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Assignment;

use App\Encounter\Application\Assignment\AssignmentRetryResult;
use App\Encounter\Application\Assignment\AssignmentState;
use App\Encounter\Application\Contract\AssignmentRetrierInterface;
use App\Encounter\Application\Contract\EncounterKeyStateReaderInterface;
use App\Encounter\Contract\Assignment\AssignmentKind;
use App\Encounter\Contract\Assignment\EncounterHospitalLockInterface;
use App\Encounter\Domain\Enum\PendingAssignmentStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;

/**
 * Automatic retries of records kept without encounter (ADR 037, #1307). One transaction per hospital under the
 * encounter hospital lock, one savepoint per record:
 *
 * | fresh result                              | open, no candidate         | deferred                                  |
 * |-------------------------------------------|----------------------------|-------------------------------------------|
 * | assigned                                  | attach, row removed        | attach, row removed                       |
 * | no candidate                              | unchanged                  | open, no candidate, candidates emptied    |
 * | ambiguous/contradictory equal to the seen | –                          | unchanged                                 |
 * | ambiguous/contradictory otherwise         | open conflict              | open conflict with the fresh candidates   |
 *
 * Only `assigned` attaches: a contradictory result stays a conflict even with a single candidate id. States
 * are compared as kind plus unordered candidate ids. The targeted retry of one record applies the same rules
 * to an open conflict (unchanged when the fresh state equals the stored one).
 *
 * Import write, reprocessing and the matcher take the hospital lock only with #1300. Until then a record or
 * encounter can change in parallel: when the handler attaches nothing (record gone or already attached) or
 * the encounter was deleted (foreign key), the record's savepoint is rolled back and the row stays for the
 * next retry. Retries record no decisions and do not change import report counters.
 *
 * @psalm-suppress UnusedClass Wired as AssignmentRetrierInterface.
 */
final readonly class AssignmentRetrier implements AssignmentRetrierInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private Connection $connection,
        private EncounterHospitalLockInterface $lock,
        private PendingAssignmentRows $rows,
        private EncounterKeyStateReaderInterface $keyStates,
    ) {
    }

    #[\Override]
    public function retryHospital(int $hospitalId): AssignmentRetryResult
    {
        $state = $this->keyStates->currentState($hospitalId);
        if ($state['key_change_in_progress']) {
            return new AssignmentRetryResult();
        }

        return $this->connection->transactional(function () use ($hospitalId, $state): AssignmentRetryResult {
            $this->lock->lock($hospitalId);

            $result = new AssignmentRetryResult();
            foreach ($this->rows->lockRetryable($hospitalId, $state['generation']) as $row) {
                $result = $result->plus($this->retryRow($row));
            }

            return $result;
        });
    }

    #[\Override]
    public function retryOne(int $hospitalId, int $pendingId): AssignmentRetryResult
    {
        $state = $this->keyStates->currentState($hospitalId);
        if ($state['key_change_in_progress']) {
            return new AssignmentRetryResult();
        }

        return $this->connection->transactional(function () use ($hospitalId, $pendingId, $state): AssignmentRetryResult {
            $this->lock->lock($hospitalId);

            $row = $this->rows->lockOne($hospitalId, $pendingId);
            if (null === $row || (int) $row['key_generation'] !== $state['generation']) {
                return new AssignmentRetryResult();
            }

            return $this->retryRow($row);
        });
    }

    #[\Override]
    public function hospitalsWithRetryableRecords(): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT DISTINCT hospital_id FROM encounter_pending_assignment WHERE (status = ? AND kind = ?) OR status = ? ORDER BY hospital_id',
            [PendingAssignmentStatus::Open->value, AssignmentKind::NoCandidate->value, PendingAssignmentStatus::Deferred->value],
        ));
    }

    /**
     * @param array{id: int|string, hospital_id: int|string, key_generation: int|string, import_id: int|string, exchange_schema: string, record_id: string, status: string, kind: string, allocation_id: int|string|null, case_key: ?string, requested_at: ?string, candidates: string, seen_kind: ?string, seen_candidates: ?string} $row
     */
    private function retryRow(array $row): AssignmentRetryResult
    {
        try {
            return $this->connection->transactional(function () use ($row): AssignmentRetryResult {
                $fresh = $this->rows->findAgain($row);

                if (AssignmentKind::Assigned === $fresh->kind) {
                    if (null === $fresh->encounterId || !$this->rows->attach($row, $fresh->encounterId)) {
                        throw new RecordChangedMeanwhile();
                    }

                    return new AssignmentRetryResult(attached: 1);
                }

                $freshState = AssignmentState::of($fresh);
                $compareWith = PendingAssignmentStatus::Deferred->value === $row['status'] && AssignmentKind::NoCandidate !== $fresh->kind
                    ? $this->rows->seen($row)
                    : $this->rows->stored($row);
                $stillDeferredWithoutCandidates = PendingAssignmentStatus::Deferred->value === $row['status'] && AssignmentKind::NoCandidate === $fresh->kind;

                if (!$stillDeferredWithoutCandidates && $compareWith instanceof AssignmentState && $freshState->equals($compareWith)) {
                    return new AssignmentRetryResult(unchanged: 1);
                }

                $this->rows->reopen((int) $row['id'], $fresh);

                return new AssignmentRetryResult(reopened: 1);
            });
        } catch (RecordChangedMeanwhile|ForeignKeyConstraintViolationException) {
            return new AssignmentRetryResult(skipped: 1);
        }
    }
}
