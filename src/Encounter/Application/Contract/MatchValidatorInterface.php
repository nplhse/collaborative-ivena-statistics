<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Application\Review\ReviewPeriod;
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
     * Records the verdict. "Wrong" on a pair also rejects the pair if it is still stored and not rejected
     * (audited like a review decision).
     *
     * @return bool whether the pair was rejected
     */
    public function record(MatchValidationItem $item, ValidationVerdict $verdict, User $user): bool;
}
