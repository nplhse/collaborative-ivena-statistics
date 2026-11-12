<?php

declare(strict_types=1);

namespace App\Encounter\Application\Import;

/**
 * The hospital's key generation is no longer the one stored on the import, or a key change is in
 * progress (ADR 026). The run fails without writing.
 */
final class EncounterKeyGenerationChangedException extends \RuntimeException
{
    public function __construct(int $importId, int $importGeneration, int $currentGeneration, bool $keyChangeInProgress)
    {
        parent::__construct(sprintf(
            'Encounter import #%d was uploaded with key generation %d; the hospital is at generation %d%s.',
            $importId,
            $importGeneration,
            $currentGeneration,
            $keyChangeInProgress ? ' with a key change in progress' : '',
        ));
    }
}
