<?php

declare(strict_types=1);

namespace App\Encounter\Application\Assignment;

use App\Encounter\Contract\Assignment\CandidateReason;

/**
 * A candidate encounter of a conflict. Visit uid and anchor are null when the encounter is gone meanwhile.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the conflict page template.
 */
final readonly class AssignmentConflictCandidate
{
    public function __construct(
        public int $encounterId,
        public CandidateReason $reason,
        public ?string $visitUid,
        public ?\DateTimeImmutable $anchorAt,
    ) {
    }
}
