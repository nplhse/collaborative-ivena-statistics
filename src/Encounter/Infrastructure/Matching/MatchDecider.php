<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Matching;

use App\Allocation\Domain\Entity\Allocation;
use App\Encounter\Application\Contract\MatchDeciderInterface;
use App\Encounter\Application\Matching\MatchingAllocation;
use App\Encounter\Application\Matching\MatchingSettings;
use App\Encounter\Application\Matching\MatchPlanner;
use App\Encounter\Application\Matching\PlannedMatch;
use App\Encounter\Application\Review\MatchDecisionException;
use App\Encounter\Application\Review\SwapProposal;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Infrastructure\Query\MatchInputQuery;
use App\Encounter\Infrastructure\Repository\EncounterMatchRepository;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Applies the decisions of the match review (ADR 029, #1054). Callers check EncounterAttribute::REVIEW
 * on the hospital first.
 *
 * Every decision runs in one transaction under the hospital's matching lock (the matcher takes the same
 * lock) and under an audit intent (or the caller's, see {@see self::audited()}); the changed rows record
 * `decided_by` and `decided_at`. Manual decisions are sticky: no matcher run changes a `confirmed` or
 * `rejected` pair. After a link is made, open proposals touching either side are removed, as the matcher
 * does at the end of a run, which also resolves a conflict.
 *
 * @psalm-suppress UnusedClass Autowired as MatchDeciderInterface.
 */
final readonly class MatchDecider implements MatchDeciderInterface
{
    public const string INTENT_DECIDED = 'encounter.match.decided';

    public const string INTENT_SWAPPED = 'encounter.match.swapped';

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EncounterMatchRepository $matches,
        private MatchInputQuery $input,
        private MatchSwapFinder $swaps,
        private MatchingSettings $settings,
        private AuditContext $auditContext,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Confirms the pair. If the allocation is linked to another visit, that pair is rejected first
     * ("change"); a visit linked to another allocation is refused (only a swap moves it).
     *
     * @return int|null the visit whose link was replaced, if any
     *
     * @throws MatchDecisionException
     */
    #[\Override]
    public function confirm(int $hospitalId, int $allocationId, int $encounterId, User $user): ?int
    {
        return $this->entityManager->wrapInTransaction(function () use ($hospitalId, $allocationId, $encounterId, $user): ?int {
            $this->matches->lockHospital($hospitalId);
            [$allocation, $matching] = $this->allocation($hospitalId, $allocationId);
            $this->assertEncounterOfHospital($hospitalId, $encounterId);

            $visitLink = $this->effectiveRow(['encounter' => $encounterId]);
            if ($visitLink instanceof EncounterMatch && $visitLink->getAllocation()->getId() !== $allocationId) {
                throw new MatchDecisionException(MatchDecisionException::VISIT_LINKED);
            }

            $pair = $this->row($allocationId, $encounterId);
            if ($pair instanceof EncounterMatch && MatchStatus::Confirmed === $pair->getStatus()) {
                throw new MatchDecisionException(MatchDecisionException::UNCHANGED);
            }

            $current = $this->effectiveRow(['allocation' => $allocationId]);
            $replaced = $current instanceof EncounterMatch && $current->getEncounter()->getId() !== $encounterId ? $current : null;
            $candidate = $pair instanceof EncounterMatch ? null : $this->candidate($hospitalId, $matching, $encounterId);

            $this->audited(self::INTENT_DECIDED, [
                'hospital_id' => $hospitalId,
                'allocation_id' => $allocationId,
                'encounter_id' => $encounterId,
                'decision' => $replaced instanceof EncounterMatch ? 'change' : 'confirm',
                'previous_status' => $pair?->getStatus()->value,
                'replaced_encounter_id' => $replaced?->getEncounter()->getId(),
            ], function () use ($replaced, $pair, $candidate, $allocation, $user): void {
                $now = $this->clock->now();
                if ($replaced instanceof EncounterMatch) {
                    $replaced->decide(MatchStatus::Rejected, $user, $now);
                    $this->entityManager->flush();
                }

                $this->confirmPair($pair, $candidate, $allocation, $user, $now);
                $this->entityManager->flush();
            });
            $this->matches->deleteProposalsTouchingLinks($hospitalId);

            return $replaced?->getEncounter()->getId();
        });
    }

    /**
     * Rejects a stored pair (proposal, automatic or confirmed link). The pair is never proposed again; a
     * rejected link frees both sides for later runs.
     *
     * @throws MatchDecisionException
     */
    #[\Override]
    public function reject(int $hospitalId, int $allocationId, int $encounterId, User $user): void
    {
        $this->entityManager->wrapInTransaction(function () use ($hospitalId, $allocationId, $encounterId, $user): void {
            $this->matches->lockHospital($hospitalId);
            $this->allocation($hospitalId, $allocationId);
            $this->assertEncounterOfHospital($hospitalId, $encounterId);

            $pair = $this->row($allocationId, $encounterId);
            if (!$pair instanceof EncounterMatch) {
                throw new MatchDecisionException(MatchDecisionException::NOT_STORED);
            }
            if (MatchStatus::Rejected === $pair->getStatus()) {
                throw new MatchDecisionException(MatchDecisionException::UNCHANGED);
            }

            $this->audited(self::INTENT_DECIDED, [
                'hospital_id' => $hospitalId,
                'allocation_id' => $allocationId,
                'encounter_id' => $encounterId,
                'decision' => 'reject',
                'previous_status' => $pair->getStatus()->value,
            ], function () use ($pair, $user): void {
                $pair->decide(MatchStatus::Rejected, $user, $this->clock->now());
                $this->entityManager->flush();
            });
        });
    }

    /**
     * Applies a swap proposal the review still offers for its allocation; refuses an outdated one.
     *
     * @throws MatchDecisionException
     */
    #[\Override]
    public function swap(int $hospitalId, int $allocationId, string $key, User $user): void
    {
        $this->entityManager->wrapInTransaction(function () use ($hospitalId, $allocationId, $key, $user): void {
            $this->matches->lockHospital($hospitalId);
            [$allocation, $matching] = $this->allocation($hospitalId, $allocationId);

            $proposal = null;
            foreach ($this->swaps->forAllocation($hospitalId, $matching) as $candidate) {
                if ($candidate->key() === $key) {
                    $proposal = $candidate;
                }
            }
            if (!$proposal instanceof SwapProposal) {
                throw new MatchDecisionException(MatchDecisionException::SWAP_OUTDATED);
            }

            $current = $this->row($allocationId, $proposal->currentEncounterId);
            if (!$current instanceof EncounterMatch || MatchStatus::AutoAccepted !== $current->getStatus()) {
                throw new MatchDecisionException(MatchDecisionException::SWAP_OUTDATED);
            }

            $newRow = $this->row($allocationId, $proposal->newEncounterId());
            $other = null;
            if ($proposal->otherLink instanceof PlannedMatch) {
                [$otherAllocation] = $this->allocation($hospitalId, $proposal->otherLink->allocationId);
                $other = [$otherAllocation, $this->row($proposal->otherLink->allocationId, $proposal->currentEncounterId), $proposal->otherLink];
            }

            $this->audited(self::INTENT_SWAPPED, [
                'hospital_id' => $hospitalId,
                'kind' => $proposal->kind,
                'allocation_id' => $allocationId,
                'previous_encounter_id' => $proposal->currentEncounterId,
                'encounter_id' => $proposal->newEncounterId(),
                'other_allocation_id' => $proposal->otherAllocationId(),
            ], function () use ($proposal, $current, $newRow, $allocation, $other, $user): void {
                $now = $this->clock->now();
                $current->decide(MatchStatus::Rejected, $user, $now);
                $this->entityManager->flush();

                $this->confirmPair($newRow, $proposal->newLink, $allocation, $user, $now);
                if (null !== $other) {
                    $this->confirmPair($other[1], $other[2], $other[0], $user, $now);
                }
                $this->entityManager->flush();
            });
            $this->matches->deleteProposalsTouchingLinks($hospitalId);
        });
    }

    /**
     * Confirms a stored pair, or creates it from its review-window scoring.
     */
    private function confirmPair(?EncounterMatch $pair, ?PlannedMatch $candidate, Allocation $allocation, User $user, \DateTimeImmutable $now): void
    {
        if (!$pair instanceof EncounterMatch) {
            if (!$candidate instanceof PlannedMatch) {
                throw new MatchDecisionException(MatchDecisionException::NOT_A_CANDIDATE);
            }

            $encounter = $this->entityManager->find(Encounter::class, $candidate->encounterId);
            if (!$encounter instanceof Encounter) {
                throw new MatchDecisionException(MatchDecisionException::NOT_FOUND);
            }

            $pair = new EncounterMatch(
                allocation: $allocation,
                encounter: $encounter,
                status: MatchStatus::Proposed,
                timeAnchor: $candidate->timeAnchor,
                timeDifferenceMinutes: $candidate->timeDifferenceMinutes,
                createdAt: $now,
                score: $candidate->score,
                points: $candidate->points,
                contributions: $candidate->contributions,
                pass: null,
                matcherVersion: MatchPlanner::VERSION,
            );
            $this->entityManager->persist($pair);
        }

        $pair->decide(MatchStatus::Confirmed, $user, $now);
    }

    /**
     * The visit scored among all candidates of the allocation's review window.
     */
    private function candidate(int $hospitalId, MatchingAllocation $allocation, int $encounterId): PlannedMatch
    {
        $window = $this->settings->reviewWindow();
        $encounters = $this->input->encounters(
            $hospitalId,
            $allocation->arrivalAt->modify(sprintf('-%d minutes', $window->minutesBefore)),
            $allocation->arrivalAt->modify(sprintf('+%d minutes', $window->minutesAfter)),
            null,
        );
        foreach (new MatchPlanner($this->settings)->candidates($allocation, $encounters, $window, 2) as $candidate) {
            if ($candidate->encounterId === $encounterId) {
                return $candidate;
            }
        }

        throw new MatchDecisionException(MatchDecisionException::NOT_A_CANDIDATE);
    }

    /**
     * @return array{0: Allocation, 1: MatchingAllocation}
     */
    private function allocation(int $hospitalId, int $allocationId): array
    {
        $allocation = $this->entityManager->find(Allocation::class, $allocationId);
        $matching = $this->input->allocation($hospitalId, $allocationId);
        if (!$allocation instanceof Allocation || !$matching instanceof MatchingAllocation) {
            throw new MatchDecisionException(MatchDecisionException::NOT_FOUND);
        }

        return [$allocation, $matching];
    }

    private function assertEncounterOfHospital(int $hospitalId, int $encounterId): void
    {
        $encounter = $this->entityManager->find(Encounter::class, $encounterId);
        if (!$encounter instanceof Encounter || $encounter->getHospital()->getId() !== $hospitalId) {
            throw new MatchDecisionException(MatchDecisionException::NOT_FOUND);
        }
    }

    private function row(int $allocationId, int $encounterId): ?EncounterMatch
    {
        return $this->matches->findOneBy(['allocation' => $allocationId, 'encounter' => $encounterId]);
    }

    /**
     * @param array{allocation?: int, encounter?: int} $side
     */
    private function effectiveRow(array $side): ?EncounterMatch
    {
        return $this->matches->findOneBy([...$side, 'status' => array_map(static fn (MatchStatus $status): string => $status->value, MatchStatus::effective())]);
    }

    /**
     * Writes under the decision's audit intent. Inside another intent (a validation verdict, #1057) the
     * decision joins that intent: its name stays, the decision is added as `decisions` to its metadata, so
     * the verdict and its links are one audited step.
     *
     * @param array<string, mixed> $metadata
     */
    private function audited(string $intent, array $metadata, callable $write): void
    {
        $outer = $this->auditContext->getCurrentIntent();
        if (null !== $outer) {
            /** @var list<array<string, mixed>> $decisions */
            $decisions = $outer['metadata']['decisions'] ?? [];
            $decisions[] = ['intent' => $intent, ...$metadata];
            $this->auditContext->endIntent();
            $this->auditContext->beginIntent($outer['name'], [...$outer['metadata'], 'decisions' => $decisions]);
            $write();

            return;
        }

        $this->auditContext->beginIntent($intent, $metadata);
        try {
            $write();
        } finally {
            $this->auditContext->endIntent();
        }
    }
}
