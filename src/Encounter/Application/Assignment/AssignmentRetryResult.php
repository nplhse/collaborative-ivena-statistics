<?php

declare(strict_types=1);

namespace App\Encounter\Application\Assignment;

/**
 * Counts of a retry run (#1307).
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the console command and tests.
 */
final readonly class AssignmentRetryResult
{
    public function __construct(
        /** Records attached to their now unique encounter. */
        public int $attached = 0,
        /** Records that became an open conflict again, or open without candidate. */
        public int $reopened = 0,
        /** Records whose state did not change. */
        public int $unchanged = 0,
        /** Records left as they were because their record or encounter changed meanwhile. */
        public int $skipped = 0,
    ) {
    }

    public function plus(self $other): self
    {
        return new self(
            $this->attached + $other->attached,
            $this->reopened + $other->reopened,
            $this->unchanged + $other->unchanged,
            $this->skipped + $other->skipped,
        );
    }
}
