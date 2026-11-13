<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Validation;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\MatchDeciderInterface;
use App\Encounter\Application\Contract\MatchValidatorInterface;
use App\Encounter\Application\Matching\MatchPlanner;
use App\Encounter\Application\Review\ReviewPeriod;
use App\Encounter\Application\Validation\ScoreBands;
use App\Encounter\Application\Validation\ValidationReport;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Entity\MatchValidationItem;
use App\Encounter\Domain\Entity\MatchValidationSample;
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
 * A verdict "wrong" on a pair rejects the pair through the review's decider (same lock, audit intent and
 * stickiness as a review decision), unless it is already rejected or no longer stored.
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
        /** @var list<array{stratum: ValidationStratum, band: ?string, allocation_id: ?int, encounter_id: ?int, pair: ?array{status: MatchStatus, score: float, points: ?int, pass: ?int, matcher_version: ?string}}> $drawn */
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
    public function record(MatchValidationItem $item, ValidationVerdict $verdict, User $user): bool
    {
        $rejected = false;
        $allocationId = $item->getAllocation()?->getId();
        $encounterId = $item->getEncounter()?->getId();
        $hospitalId = (int) $item->getSample()->getHospital()->getId();
        if (ValidationVerdict::Wrong === $verdict && $item->getStratum()->isPair() && null !== $allocationId && null !== $encounterId) {
            // Only a stored pair that is not rejected yet can be rejected; a pair a rerun dropped, or one a
            // person rejected already, keeps the verdict alone. Checked first: a refused decision would
            // close the entity manager.
            $pair = $this->matches->findOneBy(['allocation' => $allocationId, 'encounter' => $encounterId]);
            if ($pair instanceof EncounterMatch && MatchStatus::Rejected !== $pair->getStatus()) {
                $this->decider->reject($hospitalId, $allocationId, $encounterId, $user);
                $rejected = true;
            }
        }

        $this->auditContext->beginIntent(self::INTENT_VERDICT, [
            'hospital_id' => $hospitalId,
            'sample_id' => $item->getSample()->getId(),
            'item_id' => $item->getId(),
            'stratum' => $item->getStratum()->value,
            'verdict' => $verdict->value,
            'previous_verdict' => $item->getVerdict()?->value,
            'pair_rejected' => $rejected,
        ]);
        try {
            $item->record($verdict, $user, $this->clock->now());
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }

        return $rejected;
    }
}
