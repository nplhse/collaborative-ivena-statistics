<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

/**
 * Reads what the learning job of the plausibility table learns from (ADR 033, #1155), over all hospitals.
 */
interface PlausibilityLearningReaderInterface
{
    /**
     * Eligible pairs with IVENA arrival in [from, to) (no bound when null): pairs a person confirmed (in the review or
     * by a validation verdict) and automatic links whose decision did not depend on the plausibility bonus (pass 1,
     * versions before 1.2.0, or pass 2 with the bonus not decisive). Automatic links the bonus decided are never
     * returned. `from_bonus` marks confirmed pairs whose stored score had been decided by the bonus.
     *
     * @return list<array{hospital_id: int, allocation_id: int, encounter_id: int, day: string, from_bonus: bool}> ordered by hospital and arrival
     */
    public function eligiblePairs(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array;

    /**
     * Manual decisions of the hospital as "allocationId:encounterId" => belongs together (confirmed: true,
     * rejected: false), for the replay's reference.
     *
     * @return array<string, bool>
     */
    public function decidedPairs(int $hospitalId): array;
}
