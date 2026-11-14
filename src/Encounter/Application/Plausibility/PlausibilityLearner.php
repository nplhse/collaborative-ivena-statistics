<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

use App\Encounter\Application\Contract\MatchCandidateReaderInterface;
use App\Encounter\Application\Contract\MatchValidationReaderInterface;
use App\Encounter\Application\Contract\PlausibilityLearningReaderInterface;
use App\Encounter\Application\Contract\PlausibilitySnapshotBuilderInterface;
use App\Encounter\Application\Matching\MatchingAllocation;
use App\Encounter\Application\Matching\MatchingEncounter;
use App\Encounter\Application\Matching\MatchingSettings;
use App\Encounter\Application\Matching\MatchPlanner;
use App\Encounter\Domain\Entity\PlausibilitySnapshot;
use App\Encounter\Domain\Enum\PlausibilitySnapshotOrigin;
use App\Shared\Infrastructure\Audit\AuditContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The learning job of the plausibility table (ADR 033, #1155): builds a **draft** snapshot (origin `learned`) of the
 * global table from the eligible pairs of all hospitals and stores its report. It never changes the active snapshot;
 * a person activates the draft after reading the report ({@see PlausibilitySnapshots}).
 *
 * 1. Eligible pairs ({@see PlausibilityLearningReaderInterface::eligiblePairs()}): confirmed pairs and automatic links
 *    the bonus did not decide; links the bonus decided are never learned from (no feedback loop).
 * 2. Per eligible pair the other candidates of its allocation in the pass-2 window (clean, without reservations) are the
 *    negatives; diagram codes are resolved to their canonical code (ADR 032).
 * 3. The draft starts as the seed of the curated problem areas ({@see PlausibilitySnapshotBuilderInterface}); the
 *    estimated cells ({@see PlausibilityEstimator}) are written on top.
 * 4. Report ({@see PlausibilityLearningReport}): per hospital a clean replay of the production matcher over the
 *    learning period without bonus, with the active snapshot, with the draft and cross-fitted by fold.
 *
 * Everything runs in one transaction under the audit intent `encounter.plausibility.snapshot_learned`.
 */
final readonly class PlausibilityLearner
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlausibilityLearningReaderInterface $reader,
        private MatchCandidateReaderInterface $input,
        private MatchValidationReaderInterface $validation,
        private PlausibilitySnapshotBuilderInterface $builder,
        private PlausibilityLookup $lookup,
        private PlausibilityEstimator $estimator,
        private PlausibilityLearningSettings $settings,
        private MatchingSettings $matching,
        private AuditContext $auditContext,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Learns a draft from the eligible pairs with IVENA arrival on the days [from, to] (all when null).
     *
     * @throws PlausibilityException without eligible pairs
     */
    public function learn(?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): PlausibilitySnapshot
    {
        $pairs = $this->reader->eligiblePairs($from?->setTime(0, 0), $to?->setTime(0, 0)->modify('+1 day'));
        if ([] === $pairs) {
            throw new PlausibilityException(PlausibilityException::NO_ELIGIBLE_PAIRS);
        }

        $days = array_column($pairs, 'day');
        $first = new \DateTimeImmutable(min($days));
        $last = new \DateTimeImmutable(max($days));

        /** @var array<int, list<array{hospital_id: int, allocation_id: int, encounter_id: int, day: string, from_bonus: bool}>> $byHospital */
        $byHospital = [];
        foreach ($pairs as $pair) {
            $byHospital[$pair['hospital_id']][] = $pair;
        }

        $data = [];
        $observations = [];
        foreach ($byHospital as $hospitalId => $hospitalPairs) {
            $data[$hospitalId] = $this->hospitalData($hospitalId, $first, $last);
            foreach ($this->observations($hospitalId, $hospitalPairs, $data[$hospitalId]) as $observation) {
                $observations[] = $observation;
            }
        }

        $snapshot = new PlausibilitySnapshot(PlausibilitySnapshotOrigin::Learned, $this->clock->now());
        $metadata = ['from' => $first->format('Y-m-d'), 'to' => $last->format('Y-m-d'), 'parameters' => $this->settings->toArray()];
        $this->auditContext->beginIntent('encounter.plausibility.snapshot_learned', $metadata);
        try {
            $this->entityManager->wrapInTransaction(function () use ($snapshot, $observations, $byHospital, $data, $first, $last, $pairs): void {
                $this->entityManager->persist($snapshot);
                $this->entityManager->flush();
                $this->builder->rebuildFromAreas($snapshot);

                $seed = $this->lookup->table((int) $snapshot->getId());
                $this->builder->writeLearnedCells($snapshot, $this->estimator->estimate($observations, $seed));

                $report = $this->report((int) $snapshot->getId(), $seed, $observations, $byHospital, $data);
                $snapshot->recordLearning($first, $last, \count($pairs), $this->settings->toArray(), $report->toArray());
                $this->entityManager->flush();
            });
        } finally {
            $this->auditContext->endIntent();
        }

        return $snapshot;
    }

    /**
     * Allocations of the learning period and the visits around them.
     *
     * @return array{allocations: array<int, MatchingAllocation>, encounters: array<int, MatchingEncounter>}
     */
    private function hospitalData(int $hospitalId, \DateTimeImmutable $first, \DateTimeImmutable $last): array
    {
        $end = $last->modify('+1 day');
        $allocations = [];
        foreach ($this->input->allocations($hospitalId, $first, $end, null) as $allocation) {
            $allocations[$allocation->id] = $allocation;
        }

        $encounters = [];
        $visits = $this->input->encounters(
            $hospitalId,
            $first->modify(\sprintf('-%d minutes', $this->matching->widestMinutesBefore())),
            $end->modify(\sprintf('+%d minutes', $this->matching->widestMinutesAfter())),
            null,
        );
        foreach ($visits as $encounter) {
            $encounters[$encounter->id] = $encounter;
        }

        return ['allocations' => $allocations, 'encounters' => $encounters];
    }

    /**
     * @param list<array{hospital_id: int, allocation_id: int, encounter_id: int, day: string, from_bonus: bool}> $pairs
     * @param array{allocations: array<int, MatchingAllocation>, encounters: array<int, MatchingEncounter>}       $data
     *
     * @return list<PlausibilityObservation>
     */
    private function observations(int $hospitalId, array $pairs, array $data): array
    {
        $planner = new MatchPlanner($this->matching);
        $encounters = array_values($data['encounters']);
        $observations = [];
        foreach ($pairs as $pair) {
            $allocation = $data['allocations'][$pair['allocation_id']] ?? null;
            if (!$allocation instanceof MatchingAllocation) {
                continue;
            }

            $partner = $data['encounters'][$pair['encounter_id']] ?? $this->input->encounter($hospitalId, $pair['encounter_id']);
            $others = [];
            foreach ($planner->candidates($allocation, $encounters, $this->matching->pass2, MatchPlanner::PLAUSIBILITY_PASS) as $candidate) {
                $diagram = $candidate->encounterId === $pair['encounter_id'] ? null : $this->lookup->canonicalDiagram($data['encounters'][$candidate->encounterId]->mtsDiagram ?? null);
                if (null !== $diagram) {
                    $others[] = $diagram;
                }
            }

            $observations[] = new PlausibilityObservation(
                $pair['day'],
                $allocation->pzc,
                $this->lookup->canonicalDiagram($partner?->mtsDiagram),
                $others,
            );
        }

        return $observations;
    }

    /**
     * @param list<PlausibilityObservation>                                                                                   $observations
     * @param array<int, list<array{hospital_id: int, allocation_id: int, encounter_id: int, day: string, from_bonus: bool}>> $byHospital
     * @param array<int, array{allocations: array<int, MatchingAllocation>, encounters: array<int, MatchingEncounter>}>       $data
     */
    private function report(int $draftId, PlausibilityTable $seed, array $observations, array $byHospital, array $data): PlausibilityLearningReport
    {
        $canonical = fn (string $code): string => $this->lookup->canonicalDiagram($code) ?? $code;
        $active = $this->lookup->active();
        $draft = $this->lookup->ofSnapshot($draftId);

        $folds = array_map(
            static fn (PlausibilityTable $table): PlausibilityBonus => new PlausibilityBonus($table, $canonical),
            $this->estimator->foldTables($observations, $seed),
        );

        $hospitals = [];
        foreach ($byHospital as $hospitalId => $pairs) {
            $allocations = array_values($data[$hospitalId]['allocations']);
            $encounters = array_values($data[$hospitalId]['encounters']);
            $allocationIds = array_keys($data[$hospitalId]['allocations']);
            $reference = [...$this->validation->pairVerdicts($hospitalId), ...$this->reader->decidedPairs($hospitalId)];

            $variants = [];
            foreach ([PlausibilityLearningReport::NONE => PlausibilityBonus::none(), PlausibilityLearningReport::ACTIVE => $active, PlausibilityLearningReport::DRAFT => $draft] as $variant => $bonus) {
                $plan = new MatchPlanner($this->matching, plausibility: $bonus)->plan($allocations, $encounters);
                $variants[$variant] = PlausibilityLearningReport::outcome($plan, $allocationIds, $reference);
            }

            $crossPlan = [];
            foreach ($folds as $fold => $bonus) {
                $foldAllocations = array_values(array_filter($allocations, fn (MatchingAllocation $allocation): bool => $this->settings->foldOf($allocation->arrivalAt->format('Y-m-d')) === $fold));
                foreach (new MatchPlanner($this->matching, plausibility: $bonus)->plan($foldAllocations, $encounters) as $match) {
                    $crossPlan[] = $match;
                }
            }

            $variants[PlausibilityLearningReport::CROSS_FITTED] = PlausibilityLearningReport::outcome($crossPlan, $allocationIds, $reference);
            $hospitals[$hospitalId] = [
                'eligible_pairs' => \count($pairs),
                'eligible_from_bonus' => \count(array_filter($pairs, static fn (array $pair): bool => $pair['from_bonus'])),
                'variants' => $variants,
            ];
        }

        return new PlausibilityLearningReport($active->snapshotId(), $hospitals);
    }
}
