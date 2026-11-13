<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

/**
 * Reads the pseudonym key state of a hospital (ADR 027) without locking.
 */
interface EncounterKeyStateReaderInterface
{
    /**
     * Current key generation (1 for a hospital without key state) and whether a key change is in progress.
     *
     * @return array{generation: int, key_change_in_progress: bool}
     */
    public function currentState(int $hospitalId): array;
}
