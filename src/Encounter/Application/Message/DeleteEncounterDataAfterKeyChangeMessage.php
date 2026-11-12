<?php

declare(strict_types=1);

namespace App\Encounter\Application\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Deletes the encounter imports of the generations before `keyGeneration` of one hospital after a key
 * change with the option "delete", then ends the key change (ADR 026, #1050).
 */
#[AsMessage('async_priority_low')]
final readonly class DeleteEncounterDataAfterKeyChangeMessage
{
    public function __construct(
        public int $hospitalId,
        public int $keyGeneration,
    ) {
    }
}
