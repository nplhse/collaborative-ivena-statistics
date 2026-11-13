<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Application\Matching\MatchingAllocation;
use App\Encounter\Application\Review\SwapProposal;

/**
 * Targeted swap proposals of the match review (ADR 030, #1054).
 */
interface MatchSwapFinderInterface
{
    /**
     * Proposals whose allocation arrives in [from, to).
     *
     * @return list<SwapProposal> best first
     */
    public function between(int $hospitalId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /**
     * @return list<SwapProposal> at most one
     */
    public function forAllocation(int $hospitalId, MatchingAllocation $allocation): array;
}
