<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Assignment;

use App\Encounter\Contract\Import\SchemaWriteContext;

/**
 * Finds the encounter of a record of a schema that attaches to existing encounters (ADR 037).
 *
 * Candidates come only from the request's hospital and key generation. The allocation is the primary
 * anchor: its effective link decides when the pseudonym agrees or finds nothing. Nothing is guessed:
 * several candidates or a contradiction leave the record unassigned with a conflict for review.
 */
interface EncounterAssignmentsInterface
{
    /**
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); plugins and tests call it.
     */
    public function find(AssignmentRequest $request): AssignmentResult;

    /**
     * Keeps an unassigned record (no candidate, ambiguous, contradictory) with its request and
     * candidates, inside the import's transaction, so that it can be retried and reviewed (#1307).
     * $recordId identifies the record within its schema. Ignores assigned results.
     *
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); plugins and tests call it.
     */
    public function recordPending(SchemaWriteContext $context, string $schema, string $recordId, AssignmentRequest $request, AssignmentResult $result): void;
}
