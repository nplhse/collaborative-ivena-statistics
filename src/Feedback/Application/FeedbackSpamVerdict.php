<?php

declare(strict_types=1);

namespace App\Feedback\Application;

/**
 * Outcome of the spam check. The public response is the same for all three.
 */
enum FeedbackSpamVerdict: string
{
    /** Stored as normal feedback and announced. */
    case ACCEPT = 'accept';

    /** Stored in quarantine, no immediate notification. */
    case QUARANTINE = 'quarantine';

    /** Clearly invalid (honeypot, hard length limit): not stored. */
    case REJECT = 'reject';

    public function isAccept(): bool
    {
        return self::ACCEPT === $this;
    }
}
