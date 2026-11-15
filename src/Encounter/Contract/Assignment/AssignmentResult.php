<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Assignment;

/**
 * The encounter found for a record, or why there is none. Candidates are kept for review.
 *
 * @psalm-suppress PossiblyUnusedProperty Contract for encounter schema plugins (ADR 037); plugins and tests read $encounterId.
 */
final readonly class AssignmentResult
{
    /**
     * @param list<AssignmentCandidate> $candidates
     */
    private function __construct(
        public AssignmentKind $kind,
        public ?int $encounterId,
        public array $candidates,
    ) {
    }

    public static function assigned(AssignmentCandidate $candidate): self
    {
        return new self(AssignmentKind::Assigned, $candidate->encounterId, [$candidate]);
    }

    public static function noCandidate(): self
    {
        return new self(AssignmentKind::NoCandidate, null, []);
    }

    /**
     * @param list<AssignmentCandidate> $candidates at least two
     */
    public static function ambiguous(array $candidates): self
    {
        return new self(AssignmentKind::Ambiguous, null, $candidates);
    }

    /**
     * @param list<AssignmentCandidate> $candidates the allocation's encounter and the pseudonym's encounters
     */
    public static function contradictory(array $candidates): self
    {
        return new self(AssignmentKind::Contradictory, null, $candidates);
    }
}
