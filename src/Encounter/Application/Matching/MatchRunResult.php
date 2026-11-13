<?php

declare(strict_types=1);

namespace App\Encounter\Application\Matching;

/**
 * Counts of one matcher run.
 */
final readonly class MatchRunResult
{
    public function __construct(
        public int $allocations,
        public int $encounters,
        public int $autoAccepted,
        public int $proposed,
        public int $conflicts,
    ) {
    }
}
