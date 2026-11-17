<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Infrastructure\Matching;

use App\Encounter\Contract\Assignment\EncounterAllocationAssignmentsInterface;
use App\Encounter\Contract\Assignment\EncounterHospitalLockInterface;
use Cois\Plugin\EdVisit\Application\Contract\EncounterMatcherInterface;
use Cois\Plugin\EdVisit\Application\Matching\MatchingAllocation;
use Cois\Plugin\EdVisit\Application\Matching\MatchingEncounter;
use Cois\Plugin\EdVisit\Application\Matching\MatchingSettings;
use Cois\Plugin\EdVisit\Application\Matching\MatchPlanner;
use Cois\Plugin\EdVisit\Application\Matching\MatchRunResult;
use Cois\Plugin\EdVisit\Application\Matching\MatchScope;
use Cois\Plugin\EdVisit\Application\Matching\MatchWeights;
use Cois\Plugin\EdVisit\Application\Matching\PlannedMatch;
use Cois\Plugin\EdVisit\Application\Plausibility\PlausibilityLookup;
use Cois\Plugin\EdVisit\Domain\Enum\MatchStatus;
use Cois\Plugin\EdVisit\Infrastructure\Query\MatchInputQuery;
use Cois\Plugin\EdVisit\Infrastructure\Repository\EncounterMatchRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the production matcher ({@see MatchPlanner::VERSION}) for one hospital and the allocations of the given days (ADR 030, #1053).
 *
 * One transaction under the hospital's matching lock: read the scope's allocations, their candidate
 * visits and the existing links and decisions; drop the scope's open proposals; plan; write the new
 * automatic links and proposals; drop proposals that now touch a linked side. Existing links and manual
 * decisions are never changed, so a rerun only fills gaps. Pass 2 reads the plausibility bonus from the snapshot
 * active when the run starts (ADR 033); without one the bonus is 0.
 */
final readonly class EncounterMatcher implements EncounterMatcherInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MatchInputQuery $input,
        private EncounterMatchRepository $matches,
        private MatchingSettings $settings,
        private PlausibilityLookup $plausibility,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private EncounterAllocationAssignmentsInterface $assignments,
        private EncounterHospitalLockInterface $hospitalLock,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedReturnValue The console command reads it through EncounterMatcherInterface; the message handler does not need it. */
    #[\Override]
    public function run(MatchScope $scope): MatchRunResult
    {
        $result = $this->entityManager->wrapInTransaction(function () use ($scope): MatchRunResult {
            $this->hospitalLock->lock($scope->hospitalId);

            [$allocations, $encounters] = $this->load($scope);
            $allocationIds = array_map(static fn (MatchingAllocation $a): int => $a->id, $allocations);
            $state = $this->input->existingDecisions($allocationIds, array_map(static fn (MatchingEncounter $e): int => $e->id, $encounters));

            $this->matches->deleteProposalsOfAllocations($allocationIds);
            $planned = new MatchPlanner($this->settings, MatchWeights::production(), $this->plausibility->active())->plan(
                $allocations,
                $encounters,
                $state['reservedAllocations'],
                $state['reservedEncounters'],
                $state['rejectedPairs'],
            );
            $this->matches->insertPlanned($scope->hospitalId, $planned, $this->clock->now());
            $this->matches->deleteProposalsTouchingLinks($scope->hospitalId);
            // The automatic links become the encounters' effective assignment in the same transaction (#1300).
            $this->assignments->apply($this->matches->effectiveAllocationByEncounter(array_values(array_map(
                static fn (PlannedMatch $match): int => $match->encounterId,
                array_filter($planned, static fn (PlannedMatch $match): bool => MatchStatus::AutoAccepted === $match->status),
            ))), null);

            $autoAccepted = 0;
            $proposed = 0;
            $conflicts = 0;
            $decisive = 0;
            foreach ($planned as $match) {
                MatchStatus::AutoAccepted === $match->status ? ++$autoAccepted : ++$proposed;
                $conflicts += $match->conflict ? 1 : 0;
                $decisive += $match->plausibilityDecisive ? 1 : 0;
            }

            return new MatchRunResult(\count($allocations), \count($encounters), $autoAccepted, $proposed, $conflicts, $decisive);
        });

        $this->logger->info('encounter.matching.completed', [
            'hospital_id' => $scope->hospitalId,
            'days' => \count($scope->days),
            'allocations' => $result->allocations,
            'encounters' => $result->encounters,
            'auto_accepted' => $result->autoAccepted,
            'proposed' => $result->proposed,
            'conflicts' => $result->conflicts,
            'plausibility_decisive' => $result->plausibilityDecisive,
        ]);

        return $result;
    }

    /**
     * @return array{0: list<MatchingAllocation>, 1: list<MatchingEncounter>}
     */
    private function load(MatchScope $scope): array
    {
        $allocations = [];
        $encounters = [];
        foreach ($scope->ranges() as [$from, $to]) {
            foreach ($this->input->allocations($scope->hospitalId, $from, $to, $scope->ignoreImportId) as $allocation) {
                $allocations[$allocation->id] = $allocation;
            }

            $candidatesFrom = $from->modify(sprintf('-%d minutes', $this->settings->widestMinutesBefore()));
            $candidatesTo = $to->modify(sprintf('+%d minutes', $this->settings->widestMinutesAfter()));
            foreach ($this->input->encounters($scope->hospitalId, $candidatesFrom, $candidatesTo, $scope->ignoreImportId) as $encounter) {
                $encounters[$encounter->id] = $encounter;
            }
        }

        return [array_values($allocations), array_values($encounters)];
    }
}
