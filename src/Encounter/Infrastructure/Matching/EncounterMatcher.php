<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Matching;

use App\Encounter\Application\Contract\EncounterMatcherInterface;
use App\Encounter\Application\Matching\MatchingAllocation;
use App\Encounter\Application\Matching\MatchingEncounter;
use App\Encounter\Application\Matching\MatchingSettings;
use App\Encounter\Application\Matching\MatchPlanner;
use App\Encounter\Application\Matching\MatchRunResult;
use App\Encounter\Application\Matching\MatchScope;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Infrastructure\Query\MatchInputQuery;
use App\Encounter\Infrastructure\Repository\EncounterMatchRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the production matcher ({@see MatchPlanner::VERSION}) for one hospital and the allocations of the given days (ADR 029, #1053).
 *
 * One transaction under the hospital's matching lock: read the scope's allocations, their candidate
 * visits and the existing links and decisions; drop the scope's open proposals; plan; write the new
 * automatic links and proposals; drop proposals that now touch a linked side. Existing links and manual
 * decisions are never changed, so a rerun only fills gaps.
 */
final readonly class EncounterMatcher implements EncounterMatcherInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MatchInputQuery $input,
        private EncounterMatchRepository $matches,
        private MatchingSettings $settings,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedReturnValue The console command reads it through EncounterMatcherInterface; the message handler does not need it. */
    #[\Override]
    public function run(MatchScope $scope): MatchRunResult
    {
        $result = $this->entityManager->wrapInTransaction(function () use ($scope): MatchRunResult {
            $this->matches->lockHospital($scope->hospitalId);

            [$allocations, $encounters] = $this->load($scope);
            $allocationIds = array_map(static fn (MatchingAllocation $a): int => $a->id, $allocations);
            $state = $this->input->existingDecisions($allocationIds, array_map(static fn (MatchingEncounter $e): int => $e->id, $encounters));

            $this->matches->deleteProposalsOfAllocations($allocationIds);
            $planned = new MatchPlanner($this->settings)->plan(
                $allocations,
                $encounters,
                $state['reservedAllocations'],
                $state['reservedEncounters'],
                $state['rejectedPairs'],
            );
            $this->matches->insertPlanned($scope->hospitalId, $planned, $this->clock->now());
            $this->matches->deleteProposalsTouchingLinks($scope->hospitalId);

            $autoAccepted = 0;
            $proposed = 0;
            $conflicts = 0;
            foreach ($planned as $match) {
                MatchStatus::AutoAccepted === $match->status ? ++$autoAccepted : ++$proposed;
                $conflicts += $match->conflict ? 1 : 0;
            }

            return new MatchRunResult(\count($allocations), \count($encounters), $autoAccepted, $proposed, $conflicts);
        });

        $this->logger->info('encounter.matching.completed', [
            'hospital_id' => $scope->hospitalId,
            'days' => \count($scope->days),
            'allocations' => $result->allocations,
            'encounters' => $result->encounters,
            'auto_accepted' => $result->autoAccepted,
            'proposed' => $result->proposed,
            'conflicts' => $result->conflicts,
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
