<?php

declare(strict_types=1);

namespace App\Encounter\Application\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Removes encounters that no clinical record references any more (#1300, ADR 037), for one hospital or all.
 * Sent by the scheduler. Deleting an encounter import runs the cleanup for its hospital directly (#1644).
 */
#[AsMessage('async_priority_low')]
final readonly class CleanUpEncountersMessage
{
    public function __construct(
        public ?int $hospitalId = null,
    ) {
    }
}
