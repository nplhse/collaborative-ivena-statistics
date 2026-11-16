<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Application\Assignment\AssignmentDecisionOutcome;
use App\Encounter\Application\Assignment\AssignmentState;
use App\User\Domain\Entity\User;

/**
 * The two manual decisions on an open assignment conflict (ADR 037, #1307). Both re-check the conflict
 * under the encounter hospital lock against the state the reviewer saw and decide nothing if it changed.
 * Access (REVIEW grant of the hospital) is checked by the caller.
 */
interface AssignmentDeciderInterface
{
    /**
     * Attaches the record to $encounterId, which must be one of the conflict's candidates.
     */
    public function assign(int $hospitalId, int $pendingId, AssignmentState $seen, int $encounterId, User $user): AssignmentDecisionOutcome;

    /**
     * Leaves the record unassigned for now: the conflict is closed, the record stays eligible for retries.
     */
    public function defer(int $hospitalId, int $pendingId, AssignmentState $seen, User $user): AssignmentDecisionOutcome;
}
