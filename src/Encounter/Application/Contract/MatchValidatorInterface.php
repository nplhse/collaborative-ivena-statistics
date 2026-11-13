<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Application\Review\MatchDecisionException;
use App\Encounter\Application\Review\ReviewPeriod;
use App\Encounter\Application\Validation\ValidationLinkRefusedException;
use App\Encounter\Application\Validation\ValidationRecordResult;
use App\Encounter\Domain\Entity\MatchValidationItem;
use App\Encounter\Domain\Entity\MatchValidationSample;
use App\Encounter\Domain\Enum\ValidationVerdict;
use App\User\Domain\Entity\User;

/**
 * Writes of the match validation (#1057): drawing a stratified sample and recording verdicts. Callers
 * check EncounterAttribute::REVIEW on the hospital first.
 */
interface MatchValidatorInterface
{
    /**
     * Draws up to `sampleSize` items per stratum and score band; a null seed draws a new random one.
     */
    public function draw(int $hospitalId, ReviewPeriod $period, int $sampleSize, User $user, ?int $seed = null): MatchValidationSample;

    /**
     * Records the verdict and applies its link decisions in one transaction under one audit intent.
     *
     * Pairs: "correct" confirms an automatic link or proposal; "wrong" rejects the pair if it is still
     * stored and not rejected, and with a partner (another visit) links that visit instead. Unlinked
     * allocations and visits: "missed" with a partner links it. A partner is linked as the review would
     * ({@see \App\Encounter\Application\Validation\ValidationLinkPlanner}).
     *
     * @param int|null $partnerId the visit (pair, unlinked allocation) or allocation (unlinked visit) to link
     *
     * @throws ValidationLinkRefusedException when another link stands in the way; nothing is written
     * @throws MatchDecisionException         when the decider refuses (links changed in the meantime)
     */
    public function record(MatchValidationItem $item, ValidationVerdict $verdict, User $user, ?int $partnerId = null): ValidationRecordResult;
}
