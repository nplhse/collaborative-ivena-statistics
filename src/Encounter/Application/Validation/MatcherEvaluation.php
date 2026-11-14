<?php

declare(strict_types=1);

namespace App\Encounter\Application\Validation;

use App\Encounter\Application\Matching\PlannedMatch;
use App\Encounter\Domain\Enum\MatchStatus;

/**
 * Compares a replayed plan of the matcher with a reference (#1057): reviewers' verdicts on pairs, or
 * the truth of synthetic data. Pure.
 *
 * Per status (automatic link, proposal) and score band: pairs, correct, wrong, unknown (the reference
 * says nothing about the pair) and the share correct among the known ones with its Wilson interval.
 * With a complete reference (synthetic truth) it also gives the recall: the share of true pairs among the
 * replayed allocations that became automatic links, and that became an automatic link or a proposal.
 */
final readonly class MatcherEvaluation
{
    /**
     * @param list<array{status: MatchStatus, band: string, pairs: int, correct: int, wrong: int, unknown: int, share: ?ShareEstimate}> $rows
     */
    private function __construct(
        public array $rows,
        public ?int $truePairs,
        public ?int $truePairsAutomatic,
        public ?int $truePairsFound,
    ) {
    }

    /**
     * @param list<PlannedMatch>   $plan
     * @param array<string, bool>  $reference     "allocationId:encounterId" => belongs together; a pair missing here is unknown
     * @param array<int, int>|null $truth         encounter id => its true allocation id, for every visit with a true allocation (synthetic data); null without
     * @param list<int>            $allocationIds replayed allocations, for the recall
     */
    public static function of(ScoreBands $bands, array $plan, array $reference, ?array $truth = null, array $allocationIds = []): self
    {
        /** @var array<string, array{status: MatchStatus, band: string, pairs: int, correct: int, wrong: int, unknown: int}> $cells */
        $cells = [];
        foreach ([MatchStatus::AutoAccepted, MatchStatus::Proposed] as $status) {
            foreach ($bands->bands() as $band) {
                $cells[$status->value.'|'.$band['label']] = ['status' => $status, 'band' => $band['label'], 'pairs' => 0, 'correct' => 0, 'wrong' => 0, 'unknown' => 0];
            }
        }

        foreach ($plan as $match) {
            $band = $bands->bandOf($match->score);
            $key = $match->status->value.'|'.$band;
            $cells[$key] ??= ['status' => $match->status, 'band' => $band, 'pairs' => 0, 'correct' => 0, 'wrong' => 0, 'unknown' => 0];
            ++$cells[$key]['pairs'];

            $known = $reference[$match->allocationId.':'.$match->encounterId] ?? null;
            match ($known) {
                true => ++$cells[$key]['correct'],
                false => ++$cells[$key]['wrong'],
                null => ++$cells[$key]['unknown'],
            };
        }

        $rows = [];
        foreach ($cells as $cell) {
            if (0 === $cell['pairs']) {
                continue;
            }

            $rows[] = [...$cell, 'share' => ShareEstimate::of($cell['correct'], $cell['correct'] + $cell['wrong'])];
        }

        if (null === $truth) {
            return new self($rows, null, null, null);
        }

        $replayed = array_flip($allocationIds);
        $truePairs = array_filter($truth, static fn (int $allocationId): bool => isset($replayed[$allocationId]));
        $automatic = 0;
        $found = 0;
        foreach ($plan as $match) {
            if (($truePairs[$match->encounterId] ?? null) !== $match->allocationId) {
                continue;
            }

            ++$found;
            if (MatchStatus::AutoAccepted === $match->status) {
                ++$automatic;
            }
        }

        return new self($rows, \count($truePairs), $automatic, $found);
    }

    /**
     * Outcome per replayed allocation: an automatic link, else at least one proposal, else none; and how many of the
     * automatic links and proposals the plausibility bonus decided (matcher 1.2.0). Counts only, no pairs.
     *
     * @param list<PlannedMatch> $plan
     * @param list<int>          $allocationIds replayed allocations
     *
     * @return array{allocations: int, automatic: int, proposal: int, none: int, decisive_automatic: int, decisive_proposal: int}
     */
    public static function outcomes(array $plan, array $allocationIds): array
    {
        $outcome = array_fill_keys($allocationIds, 'none');
        $decisive = ['automatic' => 0, 'proposal' => 0];
        foreach ($plan as $match) {
            $automatic = MatchStatus::AutoAccepted === $match->status;
            if (isset($outcome[$match->allocationId]) && ($automatic || 'none' === $outcome[$match->allocationId])) {
                $outcome[$match->allocationId] = $automatic ? 'automatic' : 'proposal';
            }

            if ($match->plausibilityDecisive) {
                ++$decisive[$automatic ? 'automatic' : 'proposal'];
            }
        }

        $counts = array_count_values($outcome);

        return [
            'allocations' => \count($outcome),
            'automatic' => $counts['automatic'] ?? 0,
            'proposal' => $counts['proposal'] ?? 0,
            'none' => $counts['none'] ?? 0,
            'decisive_automatic' => $decisive['automatic'],
            'decisive_proposal' => $decisive['proposal'],
        ];
    }

    /**
     * Reference from a synthetic truth: a pair belongs together exactly when the visit's true allocation
     * is the pair's allocation.
     *
     * @param list<PlannedMatch> $plan
     * @param array<int, int>    $truth encounter id => true allocation id
     *
     * @return array<string, bool>
     */
    public static function referenceFromTruth(array $plan, array $truth): array
    {
        $reference = [];
        foreach ($plan as $match) {
            $reference[$match->allocationId.':'.$match->encounterId] = ($truth[$match->encounterId] ?? null) === $match->allocationId;
        }

        return $reference;
    }
}
