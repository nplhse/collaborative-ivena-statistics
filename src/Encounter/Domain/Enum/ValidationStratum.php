<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * Stratum of a match validation sample (#1057). Automatic links and proposals are sampled per score
 * band; allocations and visits without link once each.
 *
 * - `automatic`: an `auto_accepted` pair of an allocation in the period
 * - `proposal`: an open `proposed` pair of an allocation in the period
 * - `unlinked_allocation`: an allocation in the period without effective link and without open proposal
 * - `unlinked_visit`: a visit in the period (by matching anchor) with EMS or unknown arrival route, without
 *   effective link and without open proposal; self-presenters are left out, their missing link is expected
 */
enum ValidationStratum: string
{
    case Automatic = 'automatic';
    case Proposal = 'proposal';
    case UnlinkedAllocation = 'unlinked_allocation';
    case UnlinkedVisit = 'unlinked_visit';

    /** Whether items of this stratum are pairs with a score (sampled per score band). */
    public function isPair(): bool
    {
        return self::Automatic === $this || self::Proposal === $this;
    }

    /**
     * Verdicts a reviewer can record for an item of this stratum.
     *
     * @return list<ValidationVerdict>
     */
    public function verdicts(): array
    {
        return $this->isPair()
            ? [ValidationVerdict::Correct, ValidationVerdict::Wrong, ValidationVerdict::Unclear]
            : [ValidationVerdict::Missed, ValidationVerdict::NoPartner, ValidationVerdict::Unclear];
    }
}
