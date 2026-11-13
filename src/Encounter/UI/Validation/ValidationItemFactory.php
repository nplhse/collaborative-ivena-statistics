<?php

declare(strict_types=1);

namespace App\Encounter\UI\Validation;

use App\Encounter\Application\Contract\MatchCandidateReaderInterface;
use App\Encounter\Application\Matching\MatchingEncounter;
use App\Encounter\Application\Matching\MatchingSettings;
use App\Encounter\Application\Validation\ValidationLinkPlanner;
use App\Encounter\Domain\Entity\MatchValidationItem;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\ValidationStratum;
use App\Encounter\UI\Review\AllocationReviewFactory;

/**
 * View data of one validation item (#1057): one question, one decision.
 *
 * - `pair` (automatic link, proposal): the allocation compared with the sampled visit, and the other
 *   candidate visits of the review window as alternatives (by points), each with how it would be linked
 *   instead ({@see ValidationLinkPlanner}, the sampled pair released).
 * - `allocation` (unlinked allocation): every candidate visit as an option to link.
 * - `visit` (unlinked visit): every allocation whose review window contains the visit as an option, each
 *   compared as in that allocation's review.
 *
 * Comparisons come from the review ({@see AllocationReviewFactory}); `partner_ids` are the ids a verdict
 * may link (the controller accepts no other).
 */
final readonly class ValidationItemFactory
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private AllocationReviewFactory $allocations,
        private MatchCandidateReaderInterface $input,
        private MatchingSettings $settings,
        private ValidationLinkPlanner $planner,
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

        $allocationId = (int) $item->getAllocation()?->getId();
        $page = $this->allocations->create($hospitalId, $allocationId);
        if (null === $page) {
            return null;
        }

        /** @var list<array<string, mixed>&array{encounter_id: int}> $candidates */
        $candidates = $page['candidates'];
        $sampledId = $item->getEncounter()?->getId();
        $sampled = null === $sampledId ? null : array_find($candidates, static fn (array $c): bool => $c['encounter_id'] === $sampledId);
        if ($item->getStratum()->isPair() && null === $sampled) {
            return null;
        }

        $options = [];
        foreach ($candidates as $candidate) {
            if ($candidate['encounter_id'] === $sampledId) {
                continue;
            }

            $options[] = [
                'id' => $candidate['encounter_id'],
                'candidate' => $candidate,
                'allocation' => $page['allocation'],
                'allocation_extended' => $page['allocation_extended'],
                'plan' => $this->planner->plan($hospitalId, $allocationId, $candidate['encounter_id'], $sampledId),
            ];
        }

        return [
            'kind' => $item->getStratum()->isPair() ? 'pair' : 'allocation',
            'allocation' => $page['allocation'],
            'allocation_extended' => $page['allocation_extended'],
            'link' => $page['link'],
            'sampled' => $sampled,
            'options' => $options,
            'partner_ids' => array_column($options, 'id'),
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

        $options = [];
        foreach ($allocations as $allocation) {
            $page = $this->allocations->create($hospitalId, $allocation->id);
            /** @var list<array<string, mixed>&array{encounter_id: int, points: int}> $candidates */
            $candidates = $page['candidates'] ?? [];
            $candidate = array_find($candidates, static fn (array $c): bool => $c['encounter_id'] === $encounterId);
            if (null === $page || null === $candidate) {
                continue;
            }

            $options[] = [
                'id' => $allocation->id,
                'candidate' => $candidate,
                'allocation' => $page['allocation'],
                'allocation_extended' => $page['allocation_extended'],
                'link' => $page['link'],
                'plan' => $this->planner->plan($hospitalId, $allocation->id, $encounterId),
            ];
        }
        usort($options, static fn (array $a, array $b): int => [$b['candidate']['points'], $a['id']] <=> [$a['candidate']['points'], $b['id']]);

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
            'options' => $options,
            'partner_ids' => array_column($options, 'id'),
        ];
    }
}
