<?php

declare(strict_types=1);

namespace App\Encounter\UI\Review;

use App\Encounter\Application\Contract\MatchCandidateReaderInterface;
use App\Encounter\Application\Contract\MatchReviewReaderInterface;
use App\Encounter\Application\Contract\MatchSwapFinderInterface;
use App\Encounter\Application\Matching\MatchFeature;
use App\Encounter\Application\Matching\MatchingAllocation;
use App\Encounter\Application\Matching\MatchingEncounter;
use App\Encounter\Application\Matching\MatchingSettings;
use App\Encounter\Application\Matching\MatchPlanner;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\MatchStatus;

/**
 * View data of the review page of one allocation (#1054): the allocation, every candidate visit of the
 * review window with its feature contributions, the stored pair and the visit's links elsewhere, and the
 * swap proposal of the allocation.
 *
 * Candidates come from the review window, the widest bounds of all passes ({@see MatchingSettings::reviewWindow()}),
 * so every pair the matcher can store is among them. Their agreement is computed among all candidates of
 * that window; the stored score is the one of the pass that found the pair.
 */
final readonly class AllocationReviewFactory
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private MatchCandidateReaderInterface $input,
        private MatchReviewReaderInterface $review,
        private MatchSwapFinderInterface $swaps,
        private MatchingSettings $settings,
    ) {
    }

    /**
     * @return array<string, mixed>|null null when the allocation does not belong to the hospital
     */
    public function create(int $hospitalId, int $allocationId): ?array
    {
        $allocation = $this->review->allocation($hospitalId, $allocationId);
        $matching = $this->input->allocation($hospitalId, $allocationId);
        if (null === $allocation || !$matching instanceof MatchingAllocation) {
            return null;
        }

        $window = $this->settings->reviewWindow();
        $encounters = $this->input->encounters(
            $hospitalId,
            $matching->arrivalAt->modify(sprintf('-%d minutes', $window->minutesBefore)),
            $matching->arrivalAt->modify(sprintf('+%d minutes', $window->minutesAfter)),
            null,
        );
        $byId = [];
        foreach ($encounters as $encounter) {
            $byId[$encounter->id] = $encounter;
        }

        $scored = new MatchPlanner($this->settings)->candidates($matching, $encounters, $window, 2);
        $rows = $this->review->rowsTouching($allocationId, array_map(static fn (\App\Encounter\Application\Matching\PlannedMatch $c): int => $c->encounterId, $scored));

        $own = [];
        $elsewhere = [];
        $rivals = [];
        $link = null;
        foreach ($rows as $row) {
            if ($row['allocation_id'] === $allocationId) {
                $own[$row['encounter_id']] = $row;
                if ($row['status']->isEffective()) {
                    $link = $row;
                }

                continue;
            }

            if ($row['status']->isEffective()) {
                $elsewhere[$row['encounter_id']] = $row;
            } elseif (MatchStatus::Proposed === $row['status']) {
                $rivals[$row['encounter_id']][] = $row['allocation_id'];
            }
        }

        $candidates = [];
        foreach ($scored as $candidate) {
            $encounter = $byId[$candidate->encounterId];
            $candidates[] = [
                'encounter_id' => $candidate->encounterId,
                'anchor_at' => $encounter->anchorAt,
                'substitute' => AnchorSource::Assessment === $encounter->anchorSource,
                'time_difference_minutes' => $candidate->timeDifferenceMinutes,
                'agreement' => $candidate->score,
                'points' => $candidate->points,
                'features' => $this->features($matching, $encounter, $candidate->contributions),
                'stored' => $own[$candidate->encounterId] ?? null,
                'linked_elsewhere' => $elsewhere[$candidate->encounterId] ?? null,
                'rival_allocations' => $rivals[$candidate->encounterId] ?? [],
                'is_link' => null !== $link && $link['encounter_id'] === $candidate->encounterId,
            ];
        }

        return [
            'allocation' => $allocation,
            'link' => $link,
            'candidates' => $candidates,
            'swaps' => $this->swaps->forAllocation($hospitalId, $matching),
            'window' => $window,
        ];
    }

    /**
     * One entry per feature: points, direction and both compared values.
     *
     * @param array<string, int> $contributions
     *
     * @return list<array{feature: string, points: int, direction: string, ivena: string, ed: string}>
     */
    private function features(MatchingAllocation $allocation, MatchingEncounter $encounter, array $contributions): array
    {
        // Plain values (times, ages) as they are; codes as translation keys under `encounter.review.value.`.
        $values = [
            MatchFeature::Time->value => [$allocation->arrivalAt->format('H:i'), $encounter->anchorAt->format('H:i')],
            MatchFeature::Sex->value => ['key:sex_'.$allocation->sex, 'key:sex_'.$encounter->sex->value],
            MatchFeature::Age->value => [(string) $allocation->age, (string) $encounter->age],
            MatchFeature::ArrivalRoute->value => ['key:ems', 'key:route_'.$encounter->arrivalRoute->value],
            MatchFeature::Physician->value => [$allocation->withPhysician ? 'key:with_physician' : 'key:without_physician', 'key:mode_'.($encounter->arrivalMode instanceof ArrivalMode ? $encounter->arrivalMode->value : 'none')],
            MatchFeature::WorkAccident->value => [$allocation->workAccident ? 'key:yes' : 'key:no', true === $encounter->workAccident ? 'key:documented' : 'key:not_documented'],
        ];

        $features = [];
        foreach (MatchFeature::cases() as $feature) {
            $points = $contributions[$feature->value] ?? 0;
            $features[] = [
                'feature' => $feature->value,
                'points' => $points,
                'direction' => match (true) {
                    $points > 0 => 'for',
                    $points < 0 => 'against',
                    default => 'neutral',
                },
                'ivena' => $values[$feature->value][0],
                'ed' => $values[$feature->value][1],
            ];
        }

        return $features;
    }
}
