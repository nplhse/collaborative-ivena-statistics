<?php

declare(strict_types=1);

namespace App\Research\Domain\Service;

use App\Research\Domain\Enum\RoundKind;
use App\Research\Domain\Enum\RoundOutcome;
use App\Research\Domain\Enum\VoteChoice;

/**
 * The voting rules of ADR 045 as one pure function, so the result can be followed by hand.
 *
 * - A round ends at its deadline. A round without a submitted revision also ends as soon as every eligible member
 *   voted; a round after a submitted revision only at its deadline.
 * - Quorum: at least two thirds of the eligible members (rounded up) must have voted, otherwise the request counts as
 *   rejected.
 * - A veto ends the round at once.
 * - Without an enrichment: approvals alone more than half of the votes cast → approved; otherwise approvals plus
 *   revisions more than half → revision requested; otherwise rejected.
 * - With an enrichment: every vote an approval → approved; at least one revision and no rejection → revision
 *   requested; otherwise rejected.
 */
final class RoundEvaluator
{
    public static function quorum(int $eligible): int
    {
        return (int) ceil(2 * $eligible / 3);
    }

    /**
     * @param list<VoteChoice> $votes the current votes of eligible members, one per member
     *
     * @return RoundOutcome|null null while the round stays open
     */
    public static function evaluate(RoundKind $kind, int $eligible, array $votes, bool $enrichmentPlanned, bool $deadlinePassed): ?RoundOutcome
    {
        if (\in_array(VoteChoice::Veto, $votes, true)) {
            return RoundOutcome::Vetoed;
        }

        $cast = \count($votes);
        $everyoneVoted = $cast >= $eligible;
        if (!$deadlinePassed && (!$everyoneVoted || !$kind->endsEarlyWhenAllVoted())) {
            return null;
        }

        if ($cast < self::quorum($eligible) || 0 === $cast) {
            return RoundOutcome::QuorumMissed;
        }

        $approve = \count(array_filter($votes, static fn (VoteChoice $vote): bool => VoteChoice::Approve === $vote));
        $revision = \count(array_filter($votes, static fn (VoteChoice $vote): bool => VoteChoice::Revision === $vote));
        $reject = $cast - $approve - $revision;

        if ($enrichmentPlanned) {
            return match (true) {
                $approve === $cast => RoundOutcome::Approved,
                0 === $reject && $revision > 0 => RoundOutcome::RevisionRequested,
                default => RoundOutcome::Rejected,
            };
        }

        return match (true) {
            2 * $approve > $cast => RoundOutcome::Approved,
            2 * ($approve + $revision) > $cast => RoundOutcome::RevisionRequested,
            default => RoundOutcome::Rejected,
        };
    }
}
