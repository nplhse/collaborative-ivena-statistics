<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Assignment;

final readonly class AssignmentCandidate
{
    public function __construct(
        public int $encounterId,
        public CandidateReason $reason,
    ) {
    }
}
