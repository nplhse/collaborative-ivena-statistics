<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Assignment;

/**
 * One lock per hospital that serialises the writes deciding which encounter a clinical record belongs to
 * (ADR 037, #1307). Held until the end of the caller's transaction; call it inside one.
 *
 * Takes it today: the manual decisions on assignment conflicts (#1307). The encounter import write,
 * reprocessing and the matcher join it with #1300; until then they run in parallel to the decisions, which
 * therefore re-check the record and rely on the foreign keys.
 */
interface EncounterHospitalLockInterface
{
    /**
     * Blocks until the lock of the hospital is free, then holds it until the transaction ends.
     *
     * @throws \LogicException when called outside a transaction
     *
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); plugins and tests call it.
     */
    public function lock(int $hospitalId): void;
}
