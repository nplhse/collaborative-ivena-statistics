<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Application\Review\MatchDecisionException;
use App\User\Domain\Entity\User;

/**
 * Decisions of the match review (ADR 030, #1054): confirm, change, reject and swaps, each audited and
 * sticky for later matcher runs. Callers check EncounterAttribute::REVIEW on the hospital first.
 */
interface MatchDeciderInterface
{
    /**
     * Confirms the pair; if the allocation is linked to another visit, that pair is rejected ("change").
     *
     * @return int|null the visit whose link was replaced, if any
     *
     * @throws MatchDecisionException
     */
    public function confirm(int $hospitalId, int $allocationId, int $encounterId, User $user): ?int;

    /**
     * @throws MatchDecisionException
     */
    public function reject(int $hospitalId, int $allocationId, int $encounterId, User $user): void;

    /**
     * Applies the swap proposal with the given key if the review still offers it for the allocation.
     *
     * @throws MatchDecisionException
     */
    public function swap(int $hospitalId, int $allocationId, string $key, User $user): void;
}
