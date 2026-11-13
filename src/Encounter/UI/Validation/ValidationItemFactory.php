<?php

declare(strict_types=1);

namespace App\Encounter\UI\Validation;

use App\Encounter\Application\Contract\MatchCandidateReaderInterface;
use App\Encounter\Application\Matching\MatchingEncounter;
use App\Encounter\Application\Matching\MatchingSettings;
use App\Encounter\Application\Matching\MatchPlanner;
use App\Encounter\Domain\Entity\MatchValidationItem;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\ValidationStratum;
use App\Encounter\UI\Review\AllocationReviewFactory;

/**
 * View data of one validation item (#1057).
 *
 * Pairs and unlinked allocations reuse the review's comparison of the allocation with every candidate
 * visit of the review window ({@see AllocationReviewFactory}); for a pair the sampled visit comes first.
 * An unlinked visit lists the allocations whose review window contains it, each scored as if it were
 * the only candidate, so the reviewer can tell whether an allocation was missed.
 */
final readonly class ValidationItemFactory
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private AllocationReviewFactory $allocations,
        private MatchCandidateReaderInterface $input,
        private MatchingSettings $settings,
    ) {
    }

    /**
     * @return array<string, mixed>|null null when the allocation or visit is gone
     */
    public function create(MatchValidationItem $item): ?array
    {
        $hospitalId = (int) $item->getSample()->getHospital()->getId();
        if (ValidationStratum::UnlinkedVisit === $item->getStratum()) {
            return $this->visit($hospitalId, (int) $item->getEncounter()?->getId());
        }

        $page = $this->allocations->create($hospitalId, (int) $item->getAllocation()?->getId());
        if (null === $page) {
            return null;
        }

        /** @var list<array{encounter_id: int}> $candidates */
        $candidates = $page['candidates'];
        $encounterId = $item->getEncounter()?->getId();
        if (null !== $encounterId) {
            usort($candidates, static fn (array $a, array $b): int => ($b['encounter_id'] === $encounterId) <=> ($a['encounter_id'] === $encounterId));
        }

        return [
            'kind' => 'allocation',
            'allocation' => $page['allocation'],
            'allocation_extended' => $page['allocation_extended'],
            'link' => $page['link'],
            'candidates' => $candidates,
            'sampled_encounter_id' => $encounterId,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function visit(int $hospitalId, int $encounterId): ?array
    {
        $visit = $this->input->encounter($hospitalId, $encounterId);
        if (!$visit instanceof MatchingEncounter) {
            return null;
        }

        // An allocation's review window reaches from minutesBefore before to minutesAfter after its arrival,
        // so the visit lies in the windows of allocations arriving minutesAfter before to minutesBefore after it.
        $window = $this->settings->reviewWindow();
        $allocations = $this->input->allocations(
            $hospitalId,
            $visit->anchorAt->modify(sprintf('-%d minutes', $window->minutesAfter)),
            $visit->anchorAt->modify(sprintf('+%d minutes', $window->minutesBefore + 1)),
            null,
        );

        $planner = new MatchPlanner($this->settings);
        $rows = [];
        foreach ($allocations as $allocation) {
            foreach ($planner->candidates($allocation, [$visit], $window, 2) as $candidate) {
                $rows[] = [
                    'allocation_id' => $allocation->id,
                    'arrival_at' => $allocation->arrivalAt,
                    'sex' => $allocation->sex,
                    'age' => $allocation->age,
                    'with_physician' => $allocation->withPhysician,
                    'work_accident' => $allocation->workAccident,
                    'time_difference_minutes' => $candidate->timeDifferenceMinutes,
                    'points' => $candidate->points,
                    'agreement_alone' => MatchPlanner::agreementAlone($candidate->points),
                ];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$b['points'], $a['allocation_id']] <=> [$a['points'], $b['allocation_id']]);

        return [
            'kind' => 'visit',
            'visit' => [
                'encounter_id' => $visit->id,
                'anchor_at' => $visit->anchorAt,
                'substitute' => AnchorSource::Assessment === $visit->anchorSource,
                'sex' => $visit->sex->value,
                'age' => $visit->age,
                'arrival_route' => $visit->arrivalRoute->value,
                'arrival_mode' => $visit->arrivalMode?->value,
                'work_accident' => $visit->workAccident,
            ],
            'nearby' => $rows,
        ];
    }
}
