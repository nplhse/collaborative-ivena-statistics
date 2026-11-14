<?php

declare(strict_types=1);

namespace App\Encounter\UI\Console\Command;

use App\Encounter\Application\Contract\MatchCandidateReaderInterface;
use App\Encounter\Application\Contract\MatchValidationReaderInterface;
use App\Encounter\Application\Contract\PlausibilityRepositoryInterface;
use App\Encounter\Application\Matching\MatchingAllocation;
use App\Encounter\Application\Matching\MatchingSettings;
use App\Encounter\Application\Matching\MatchPlanner;
use App\Encounter\Application\Matching\MatchWeights;
use App\Encounter\Application\Plausibility\PlausibilityBonus;
use App\Encounter\Application\Plausibility\PlausibilityLookup;
use App\Encounter\Application\Validation\MatcherEvaluation;
use App\Encounter\Application\Validation\ScoreBands;
use App\Encounter\Domain\Enum\MatchStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Replays the matcher on the stored allocations and visits of one hospital and period with changed
 * thresholds or points, and compares the result with the reviewers' verdicts or a synthetic truth (#1057).
 * Read-only: it writes no link, so it serves to check weights and thresholds before a new matcher version
 * is introduced.
 *
 * The replay is a clean run: no existing links, no manual decisions, all allocations of the period.
 * Without --truth, a pair counts as correct or wrong only when a validation verdict exists for it.
 *
 * Plausibility bonus (matcher 1.2.0): pass 2 reads the active snapshot, or the one given with --snapshot=<id> (also a
 * draft or retired one), or none with --snapshot=none. The output compares the allocations linked automatically, with
 * a proposal and without either, with and without the bonus. It prints counts only, never a pair or a case.
 */
#[AsCommand(
    name: 'app:encounter:evaluate-matcher',
    description: 'Replay the matcher with changed thresholds or points on a hospital and period and report correct links per score band (read-only).',
)]
final readonly class EvaluateMatcherCommand
{
    public function __construct(
        private MatchCandidateReaderInterface $input,
        private MatchValidationReaderInterface $validation,
        private MatchingSettings $settings,
        private ScoreBands $bands,
        private PlausibilityLookup $plausibility,
        private PlausibilityRepositoryInterface $plausibilityRepository,
    ) {
    }

    /**
     * @param list<string> $points
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Hospital id')]
        int $hospital = 0,
        #[Option(description: 'First arrival day of the allocations (YYYY-MM-DD)')]
        string $from = '',
        #[Option(description: 'Last arrival day of the allocations (YYYY-MM-DD), default: same as --from')]
        string $to = '',
        #[Option(description: 'Auto-accept threshold instead of the configured one')]
        ?float $autoAccept = null,
        #[Option(description: 'Proposal threshold instead of the configured one')]
        ?float $proposal = null,
        #[Option(description: 'Matcher version whose points the replay starts from, e.g. 1.0.0; default: the production matcher')]
        string $weights = MatchPlanner::VERSION,
        #[Option(description: 'Points of the "no match" option instead of the ones of --weights')]
        ?int $noMatchPoints = null,
        #[Option(description: 'Changed point as key=value, e.g. time.near=1 (repeatable; keys as in MatchWeights)')]
        array $points = [],
        #[Option(description: 'Label of the replayed weights in the output')]
        string $label = 'replay',
        #[Option(description: 'Synthetic truth file (truth.json of app:generate:encounter-files) instead of the verdicts')]
        string $truth = '',
        #[Option(description: 'Plausibility snapshot id for the pass-2 bonus (also a draft), or "none"; default: the active snapshot')]
        string $snapshot = '',
    ): int {
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $last = '' === $to ? $first : \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($hospital < 1 || !$first instanceof \DateTimeImmutable || !$last instanceof \DateTimeImmutable || $last < $first) {
            $io->error('Pass --hospital=<id> and --from=YYYY-MM-DD, optionally --to=YYYY-MM-DD not before --from.');

            return Command::INVALID;
        }

        try {
            $replayed = MatchWeights::named($weights)->with($label, $this->parsePoints($points), $noMatchPoints);
            $settings = new MatchingSettings(
                $autoAccept ?? $this->settings->autoAcceptThreshold,
                $proposal ?? $this->settings->proposalThreshold,
                ['minutes_before' => $this->settings->pass1->minutesBefore, 'minutes_after' => $this->settings->pass1->minutesAfter, 'max_age_difference' => $this->settings->pass1->maxAgeDifference],
                ['minutes_before' => $this->settings->pass2->minutesBefore, 'minutes_after' => $this->settings->pass2->minutesAfter, 'max_age_difference' => $this->settings->pass2->maxAgeDifference],
            );
            $truthMap = '' === $truth ? null : $this->readTruth($hospital, $truth);
            [$bonus, $bonusLabel] = $this->bonus($snapshot);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $allocations = $this->input->allocations($hospital, $first, $last->modify('+1 day'), null);
        $encounters = $this->input->encounters(
            $hospital,
            $first->modify(sprintf('-%d minutes', $settings->widestMinutesBefore())),
            $last->modify('+1 day')->modify(sprintf('+%d minutes', $settings->widestMinutesAfter())),
            null,
        );
        $plan = new MatchPlanner($settings, $replayed, $bonus)->plan($allocations, $encounters);
        $allocationIds = array_map(static fn (MatchingAllocation $allocation): int => $allocation->id, $allocations);
        $withBonus = MatcherEvaluation::outcomes($plan, $allocationIds);
        $withoutBonus = MatcherEvaluation::outcomes(new MatchPlanner($settings, $replayed)->plan($allocations, $encounters), $allocationIds);

        $reference = null === $truthMap ? $this->validation->pairVerdicts($hospital) : MatcherEvaluation::referenceFromTruth($plan, $truthMap);
        $evaluation = MatcherEvaluation::of(
            $this->bands,
            $plan,
            $reference,
            $truthMap,
            $allocationIds,
        );

        $io->definitionList(
            ['Weights' => sprintf('%s, based on %s (no match %d)', $replayed->version, $weights, $replayed->noMatchPoints)],
            ['Thresholds' => sprintf('auto %.2f, proposal %.2f', $settings->autoAcceptThreshold, $settings->proposalThreshold)],
            ['Plausibility bonus' => 0 === $replayed->plausibilityCap ? sprintf('none (matcher %s has no bonus)', $weights) : sprintf('%s, pass 2, cap +%d', $bonusLabel, $replayed->plausibilityCap)],
            ['Reference' => null === $truthMap ? 'validation verdicts' : 'synthetic truth'],
            ['Allocations / visits' => sprintf('%d / %d', \count($allocations), \count($encounters))],
        );
        $io->table(
            ['Status', 'Score band', 'Pairs', 'Correct', 'Wrong', 'Unknown', 'Share correct (95 %)'],
            array_map(static fn (array $row): array => [
                MatchStatus::AutoAccepted === $row['status'] ? 'automatic' : 'proposal',
                $row['band'],
                $row['pairs'],
                $row['correct'],
                $row['wrong'],
                $row['unknown'],
                null === $row['share'] ? '–' : sprintf('%.3f (%.3f–%.3f)', $row['share']->share, $row['share']->lower, $row['share']->upper),
            ], $evaluation->rows),
        );

        $io->table(
            ['Plausibility bonus', 'Allocations', 'Automatic', 'Proposal', 'None', 'Decided by the bonus (automatic / proposal)'],
            [
                ['with', $withBonus['allocations'], $this->ratio($withBonus['automatic'], $withBonus['allocations']), $this->ratio($withBonus['proposal'], $withBonus['allocations']), $this->ratio($withBonus['none'], $withBonus['allocations']), sprintf('%d / %d', $withBonus['decisive_automatic'], $withBonus['decisive_proposal'])],
                ['without', $withoutBonus['allocations'], $this->ratio($withoutBonus['automatic'], $withoutBonus['allocations']), $this->ratio($withoutBonus['proposal'], $withoutBonus['allocations']), $this->ratio($withoutBonus['none'], $withoutBonus['allocations']), '–'],
            ],
        );

        if (null !== $evaluation->truePairs) {
            $io->table(['True pairs', 'Automatic', 'Automatic or proposal'], [[
                $evaluation->truePairs,
                $this->ratio((int) $evaluation->truePairsAutomatic, $evaluation->truePairs),
                $this->ratio((int) $evaluation->truePairsFound, $evaluation->truePairs),
            ]]);
        }

        $io->note('Nothing was written. The score stays an agreement until it is calibrated (ADR 030).');

        return Command::SUCCESS;
    }

    /**
     * The snapshot for the pass-2 bonus and its label: '' the active one, 'none' no bonus, otherwise a snapshot id.
     *
     * @return array{0: PlausibilityBonus, 1: string}
     */
    private function bonus(string $snapshot): array
    {
        if ('none' === $snapshot) {
            return [PlausibilityBonus::none(), 'no snapshot'];
        }

        if ('' === $snapshot) {
            $active = $this->plausibility->active();
            $id = $active->snapshotId();

            return [$active, null === $id ? 'no active snapshot' : sprintf('active snapshot %d', $id)];
        }

        if (1 !== preg_match('/^[1-9]\d*$/', $snapshot)) {
            throw new \InvalidArgumentException(sprintf('Expected --snapshot=<id> or --snapshot=none, got "%s".', $snapshot));
        }

        $id = (int) $snapshot;
        foreach ($this->plausibilityRepository->snapshots() as $candidate) {
            if ($candidate->getId() === $id) {
                return [$this->plausibility->ofSnapshot($id), sprintf('snapshot %d (%s)', $id, $candidate->getStatus()->value)];
            }
        }

        throw new \InvalidArgumentException(sprintf('Plausibility snapshot %d does not exist.', $id));
    }

    /**
     * @param list<string> $points
     *
     * @return array<string, int>
     */
    private function parsePoints(array $points): array
    {
        $parsed = [];
        foreach ($points as $point) {
            if (1 !== preg_match('/^([a-z_]+\.[a-z0-9_]+)=(-?\d+)$/', $point, $match)) {
                throw new \InvalidArgumentException(sprintf('Expected --points=key=value, e.g. time.near=1, got "%s".', $point));
            }

            $parsed[$match[1]] = (int) $match[2];
        }

        return $parsed;
    }

    /**
     * Visit id => true allocation id from a synthetic truth file; visits not stored in the hospital are
     * left out.
     *
     * @return array<int, int>
     */
    private function readTruth(int $hospitalId, string $path): array
    {
        $content = is_file($path) ? file_get_contents($path) : false;
        $data = false === $content ? null : json_decode($content, true);
        if (!\is_array($data) || 'synthetic-encounter-truth' !== ($data['format'] ?? null) || !\is_array($data['visits'] ?? null)) {
            throw new \InvalidArgumentException(sprintf('"%s" is no synthetic truth file.', $path));
        }

        /** @var array<string, int> $allocationByFingerprint */
        $allocationByFingerprint = [];
        foreach ($data['visits'] as $visit) {
            if (!\is_array($visit) || !\is_int($visit['allocation_id'] ?? null) || !\is_array($visit['visit_fingerprints'] ?? null)) {
                continue;
            }

            foreach ($visit['visit_fingerprints'] as $fingerprint) {
                if (\is_string($fingerprint)) {
                    $allocationByFingerprint[$fingerprint] = $visit['allocation_id'];
                }
            }
        }

        $truth = [];
        foreach ($this->validation->encounterIdsByFingerprint($hospitalId, array_keys($allocationByFingerprint)) as $fingerprint => $encounterId) {
            $truth[$encounterId] = $allocationByFingerprint[$fingerprint];
        }

        return $truth;
    }

    private function ratio(int $part, int $total): string
    {
        return 0 === $total ? '–' : sprintf('%d (%.3f)', $part, $part / $total);
    }
}
