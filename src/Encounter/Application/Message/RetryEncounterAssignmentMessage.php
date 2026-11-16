<?php

declare(strict_types=1);

namespace App\Encounter\Application\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Retries exactly one pending record (#1307), also an open conflict: sent after a manual decision found that
 * the assignment now has a unique encounter, so that the conflict does not stay open.
 */
#[AsMessage('async_priority_low')]
final readonly class RetryEncounterAssignmentMessage
{
    public function __construct(
        public int $hospitalId,
        public int $pendingId,
    ) {
    }
}
