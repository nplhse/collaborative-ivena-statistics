<?php

declare(strict_types=1);

namespace App\Encounter\Application\Assignment;

use App\Encounter\Contract\Assignment\CandidateReason;

/**
 * A candidate encounter of a conflict. The anchor is null when the encounter is gone meanwhile; the link to its
 * record page is null as well when no encounter schema offers one.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the conflict page template.
 */
final readonly class AssignmentConflictCandidate
{
    public function __construct(
        public int $encounterId,
        public CandidateReason $reason,
        public ?string $link,
        public ?\DateTimeImmutable $anchorAt,
    ) {
    }
}
