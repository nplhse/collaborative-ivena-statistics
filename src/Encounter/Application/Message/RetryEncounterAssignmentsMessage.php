<?php

declare(strict_types=1);

namespace App\Encounter\Application\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Retries the records of a hospital that wait without encounter (#1307): records without candidate and records
 * left unassigned for now, after an encounter import of the hospital added encounters. Open conflicts wait for
 * review.
 */
#[AsMessage('async_priority_low')]
final readonly class RetryEncounterAssignmentsMessage
{
    public function __construct(
        public int $hospitalId,
    ) {
    }
}
