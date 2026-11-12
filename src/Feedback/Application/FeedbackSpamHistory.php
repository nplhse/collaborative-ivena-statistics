<?php

declare(strict_types=1);

namespace App\Feedback\Application;

/**
 * What earlier feedback within the history window says about a new submission: how often the guest email
 * and the message fingerprint occurred before.
 */
final readonly class FeedbackSpamHistory
{
    public function __construct(
        public int $earlierEntriesFromGuestEmail = 0,
        public int $earlierEntriesWithSameMessage = 0,
    ) {
    }
}
