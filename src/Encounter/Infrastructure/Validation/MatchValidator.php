<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Validation;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\MatchDeciderInterface;
use App\Encounter\Application\Contract\MatchValidatorInterface;
use App\Encounter\Application\Matching\MatchPlanner;
use App\Encounter\Application\Review\ReviewPeriod;
use App\Encounter\Application\Review\SwapProposal;
use App\Encounter\Application\Validation\ScoreBands;
use App\Encounter\Application\Validation\ValidationLinkPlan;
use App\Encounter\Application\Validation\ValidationLinkPlanner;
use App\Encounter\Application\Validation\ValidationLinkRefusedException;
use App\Encounter\Application\Validation\ValidationRecordResult;
use App\Encounter\Application\Validation\ValidationReport;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Entity\MatchValidationItem;
use App\Encounter\Domain\Entity\MatchValidationSample;
use App\Encounter\Domain\Entity\PlausibilitySnapshot;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\ValidationStratum;
use App\Encounter\Domain\Enum\ValidationVerdict;
use App\Encounter\Infrastructure\Query\ValidationSampleQuery;
use App\Encounter\Infrastructure\Repository\EncounterMatchRepository;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Draws match validation samples and records verdicts (#1057).
 *
 * Drawing reads the population of each stratum (pairs per score band) in the reproducible order of
 * {@see ValidationSampleQuery} and keeps the first `sampleSize` of each cell. It changes no link:
 * automatic links stay effective while they are validated.
 *
 * Recording a verdict applies its link decisions through the review's decider (same lock and stickiness as
 * a review decision), in one transaction with the verdict and under the verdict's audit intent: "correct"
 * confirms an automatic link or proposal, "wrong" rejects the pair, a chosen partner is linked (for a pair
 * "instead"). Changing a verdict later undoes no link: links are manual decisions now.
 *
 * @psalm-suppress UnusedClass Autowired as MatchValidatorInterface.
 */
final readonly class MatchValidator implements MatchValidatorInterface
{
    public const string INTENT_DRAWN = 'encounter.validation.sample_drawn';

    public const string INTENT_VERDICT = 'encounter.validation.verdict_recorded';

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ValidationSampleQuery $population,
        private MatchDeciderInterface $decider,
        private ValidationLinkPlanner $planner,
        private EncounterMatchRepository $matches,
        private ScoreBands $bands,
        private AuditContext $auditContext,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function draw(int $hospitalId, ReviewPeriod $period, int $sampleSize, User $user, ?int $seed = null): MatchValidationSample
    {
        $hospital = $this->entityManager->find(Hospital::class, $hospitalId) ?? throw new \InvalidArgumentException('Unknown hospital.');
        $seed ??= random_int(1, 2_147_483_647);

        /** @var array<string, int> $population */
        $population = [];
        /** @var list<array{stratum: ValidationStratum, band: ?string, allocation_id: ?int, encounter_id: ?int, pair: ?array{status: MatchStatus, score: float, points: ?int, pass: ?int, matcher_version: ?string, plausibility_snapshot_id: ?int, plausibility_decisive: bool}}> $drawn */
        $drawn = [];
        foreach ([ValidationStratum::Automatic, ValidationStratum::Proposal] as $stratum) {
            foreach ($this->population->pairs($hospitalId, $period, $stratum, $seed) as $pair) {
                $band = $this->bands->bandOf($pair['score']);
                $key = ValidationReport::cellKey($stratum, $band);
                $population[$key] = ($population[$key] ?? 0) + 1;
                if ($population[$key] <= $sampleSize) {
                    $drawn[] = ['stratum' => $stratum, 'band' => $band, 'allocation_id' => $pair['allocation_id'], 'encounter_id' => $pair['encounter_id'], 'pair' => $pair];
                }
            }
        }

        $allocations = $this->population->unlinkedAllocations($hospitalId, $period, $seed);
        $population[ValidationStratum::UnlinkedAllocation->value] = \count($allocations);
        foreach (\array_slice($allocations, 0, $sampleSize) as $id) {
            $drawn[] = ['stratum' => ValidationStratum::UnlinkedAllocation, 'band' => null, 'allocation_id' => $id, 'encounter_id' => null, 'pair' => null];
        }

        $visits = $this->population->unlinkedVisits($hospitalId, $period, $seed);
        $population[ValidationStratum::UnlinkedVisit->value] = \count($visits);
        foreach (\array_slice($visits, 0, $sampleSize) as $id) {
            $drawn[] = ['stratum' => ValidationStratum::UnlinkedVisit, 'band' => null, 'allocation_id' => null, 'encounter_id' => $id, 'pair' => null];
        }

        $sample = new MatchValidationSample(
            hospital: $hospital,
            periodFrom: $period->from,
            periodTo: $period->to,
            sampleSize: $sampleSize,
            seed: $seed,
            matcherVersion: MatchPlanner::VERSION,
            scoreBands: $this->bands->lowerBounds,
            population: $population,
            createdBy: $user,
            createdAt: $this->clock->now(),
        );

        $this->auditContext->beginIntent(self::INTENT_DRAWN, [
            'hospital_id' => $hospitalId,
            'from' => $period->from->format('Y-m-d'),
            'to' => $period->to->format('Y-m-d'),
            'sample_size' => $sampleSize,
            'seed' => $seed,
            'items' => \count($drawn),
        ]);
        try {
            $this->entityManager->wrapInTransaction(function () use ($sample, $drawn): void {
                $this->entityManager->persist($sample);
                foreach ($drawn as $index => $row) {
                    $snapshotId = $row['pair']['plausibility_snapshot_id'] ?? null;
                    $this->entityManager->persist(new MatchValidationItem(
                        sample: $sample,
                        position: $index + 1,
                        stratum: $row['stratum'],
                        allocation: null === $row['allocation_id'] ? null : $this->entityManager->getReference(Allocation::class, $row['allocation_id']),
                        encounter: null === $row['encounter_id'] ? null : $this->entityManager->getReference(Encounter::class, $row['encounter_id']),
                        band: $row['band'],
                        sampledStatus: $row['pair']['status'] ?? null,
                        score: $row['pair']['score'] ?? null,
                        points: $row['pair']['points'] ?? null,
                        pass: $row['pair']['pass'] ?? null,
                        matcherVersion: $row['pair']['matcher_version'] ?? null,
                        plausibilitySnapshot: null === $snapshotId ? null : $this->entityManager->getReference(PlausibilitySnapshot::class, $snapshotId),
                        plausibilityDecisive: $row['pair']['plausibility_decisive'] ?? false,
                    ));
                }
                $this->entityManager->flush();
            });
        } finally {
            $this->auditContext->endIntent();
        }

        return $sample;
    }

    #[\Override]
    public function record(MatchValidationItem $item, ValidationVerdict $verdict, User $user, ?int $partnerId = null): ValidationRecordResult
    {
        $stratum = $item->getStratum();
        $allocationId = $item->getAllocation()?->getId();
        $encounterId = $item->getEncounter()?->getId();
        $hospitalId = (int) $item->getSample()->getHospital()->getId();

        // Everything is checked before the first write: a refused decision inside the transaction would
        // close the entity manager.
        $link = null === $partnerId ? null : $this->linkTarget($item, $verdict, $partnerId);
        $released = $stratum->isPair() && ValidationVerdict::Wrong === $verdict ? $encounterId : null;
        $plan = null === $link ? null : $this->planner->plan($hospitalId, $link[0], $link[1], $released);
        if ($plan instanceof ValidationLinkPlan && ValidationLinkPlan::REFUSED === $plan->action) {
            throw new ValidationLinkRefusedException((int) $plan->allocationId);
        }

        $pair = $stratum->isPair() && null !== $allocationId && null !== $encounterId
            ? $this->matches->findOneBy(['allocation' => $allocationId, 'encounter' => $encounterId])
            : null;
        $pairStatus = $pair instanceof EncounterMatch ? $pair->getStatus() : null;
        // "Wrong" rejects a stored pair that is not rejected yet; a pair a rerun dropped, or one a person
        // rejected already, keeps the verdict alone.
        $reject = null !== $released && $pairStatus instanceof MatchStatus && MatchStatus::Rejected !== $pairStatus;
        // "Correct" confirms an automatic link or open proposal the review could confirm as it is; a pair a
        // person decided already, or one a rerun dropped, keeps the verdict alone.
        $confirm = ValidationVerdict::Correct === $verdict && \in_array($pairStatus, [MatchStatus::AutoAccepted, MatchStatus::Proposed], true)
            && ValidationLinkPlan::CONFIRM === $this->planner->plan($hospitalId, (int) $allocationId, (int) $encounterId)->action;

        $linkAction = $plan instanceof ValidationLinkPlan ? $plan->action : null;
        $swap = $plan instanceof ValidationLinkPlan ? $plan->swap : null;
        $outcome = match (true) {
            ValidationLinkPlan::SWAP === $linkAction => ValidationRecordResult::SWAPPED,
            ValidationLinkPlan::CONFIRM === $linkAction => ValidationRecordResult::LINKED,
            $confirm => ValidationRecordResult::CONFIRMED,
            $reject => ValidationRecordResult::REJECTED,
            default => ValidationRecordResult::RECORDED,
        };
        $replacement = $stratum->isPair() && null !== $link ? $link[1] : null;

        $this->auditContext->beginIntent(self::INTENT_VERDICT, [
            'hospital_id' => $hospitalId,
            'sample_id' => $item->getSample()->getId(),
            'item_id' => $item->getId(),
            'stratum' => $stratum->value,
            'verdict' => $verdict->value,
            'previous_verdict' => $item->getVerdict()?->value,
            'pair_rejected' => $reject,
            'pair_confirmed' => $confirm,
            'link' => null === $link ? null : [
                'action' => $linkAction,
                'allocation_id' => $link[0],
                'encounter_id' => $link[1],
                'swap' => $swap?->key(),
            ],
            'replacement_encounter_id' => $replacement,
        ]);
        try {
            $this->entityManager->wrapInTransaction(function () use ($item, $verdict, $user, $hospitalId, $allocationId, $encounterId, $reject, $confirm, $link, $linkAction, $swap, $replacement): void {
                if ($reject) {
                    $this->decider->reject($hospitalId, (int) $allocationId, (int) $encounterId, $user);
                }
                if ($confirm) {
                    $this->decider->confirm($hospitalId, (int) $allocationId, (int) $encounterId, $user);
                }
                if (null !== $link && ValidationLinkPlan::CONFIRM === $linkAction) {
                    $this->decider->confirm($hospitalId, $link[0], $link[1], $user);
                }
                if ($swap instanceof SwapProposal) {
                    $this->decider->swap($hospitalId, $swap->allocationId, $swap->key(), $user);
                }

                $item->record($verdict, $user, $this->clock->now(), null === $replacement ? null : $this->entityManager->getReference(Encounter::class, $replacement));
                $this->entityManager->flush();
            });
        } finally {
            $this->auditContext->endIntent();
        }

        return new ValidationRecordResult($outcome);
    }

    /**
     * The pair a partner makes with the item: [allocation id, visit id].
     *
     * @return array{0: int, 1: int}
     */
    private function linkTarget(MatchValidationItem $item, ValidationVerdict $verdict, int $partnerId): array
    {
        $allocationId = $item->getAllocation()?->getId();
        $encounterId = $item->getEncounter()?->getId();

        return match (true) {
            $item->getStratum()->isPair() && ValidationVerdict::Wrong === $verdict && null !== $allocationId && $partnerId !== $encounterId => [$allocationId, $partnerId],
            ValidationStratum::UnlinkedAllocation === $item->getStratum() && ValidationVerdict::Missed === $verdict && null !== $allocationId => [$allocationId, $partnerId],
            ValidationStratum::UnlinkedVisit === $item->getStratum() && ValidationVerdict::Missed === $verdict && null !== $encounterId => [$partnerId, $encounterId],
            default => throw new \InvalidArgumentException(sprintf('A partner does not go with verdict "%s" on stratum "%s".', $verdict->value, $item->getStratum()->value)),
        };
    }
}
