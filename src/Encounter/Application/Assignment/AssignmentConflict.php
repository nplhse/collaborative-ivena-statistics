<?php

declare(strict_types=1);

namespace App\Encounter\Application\Assignment;

use App\Encounter\Contract\Assignment\AssignmentKind;

/**
 * An open assignment conflict of a record (ADR 037) as listed for review: ambiguous or contradictory, with
 * its candidates, decided on the conflict page (#1307).
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the conflict page template.
 */
final readonly class AssignmentConflict
{
    /**
     * @param list<AssignmentConflictCandidate> $candidates
     */
    public function __construct(
        public int $id,
        public string $schema,
        public string $recordId,
        public AssignmentKind $kind,
        public int $importId,
        public string $importName,
        public \DateTimeImmutable $createdAt,
        public array $candidates,
    ) {
    }

    /**
     * The conflict as the reviewer sees it, carried by the decision forms.
     *
     * @psalm-suppress PossiblyUnusedMethod Called by the conflict page template.
     */
    public function state(): AssignmentState
    {
        return new AssignmentState($this->kind, array_map(static fn (AssignmentConflictCandidate $candidate): int => $candidate->encounterId, $this->candidates));
    }
}
