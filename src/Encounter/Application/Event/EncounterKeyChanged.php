<?php

declare(strict_types=1);

namespace App\Encounter\Application\Event;

use App\Encounter\Domain\Enum\EncounterKeyChangeOption;

/**
 * The pseudonym key of a hospital was changed (ADR 026); dispatched after the commit. With the option
 * "delete" the old data is still being deleted in the worker.
 */
final readonly class EncounterKeyChanged
{
    public function __construct(
        public int $hospitalId,
        public string $hospitalName,
        public int $keyGeneration,
        public EncounterKeyChangeOption $option,
        public string $actorUsername,
    ) {
    }
}
