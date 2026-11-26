<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Assignment;

/**
 * Assignment counts of the records an import inserted, for the import report: `unassigned` counts only
 * records without candidate, `conflicts` the ambiguous and contradictory ones.
 */
final readonly class AssignmentCounts
{
    public function __construct(
        public int $assigned = 0,
        public int $unassigned = 0,
        public int $conflicts = 0,
    ) {
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); plugins and tests call it.
     */
    public function with(AssignmentResult $result): self
    {
        return match (true) {
            AssignmentKind::Assigned === $result->kind => new self($this->assigned + 1, $this->unassigned, $this->conflicts),
            AssignmentKind::NoCandidate === $result->kind => new self($this->assigned, $this->unassigned + 1, $this->conflicts),
            default => new self($this->assigned, $this->unassigned, $this->conflicts + 1),
        };
    }
}
