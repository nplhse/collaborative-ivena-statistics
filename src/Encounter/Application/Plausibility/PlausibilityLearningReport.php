<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

use App\Encounter\Application\Matching\PlannedMatch;
use App\Encounter\Domain\Enum\MatchStatus;

/**
 * Replay figures of a learned snapshot per hospital (ADR 033, #1155), stored with the snapshot.
 *
 * Per hospital: the eligible pairs it contributed, how many of them a person confirmed from a proposal the bonus had
 * decided, and per variant a clean replay of the production matcher over the learning period:
 *
 * - `none`: no bonus;
 * - `active`: the snapshot active when the draft was learned (no bonus without one);
 * - `draft`: the learned draft, learned on all eligible data (in-sample);
 * - `cross_fitted`: each fold of days replayed with a table learned on the other folds only, added up — the honest
 *   estimate of the draft's effect.
 *
 * Per variant: allocations; allocations with an automatic link, with a proposal only, with neither; automatic links the
 * bonus decided; automatic links that agree with the reference (a confirmed pair or a "correct" verdict) and that
 * contradict it (a rejected pair or "wrong" verdict, or another visit or allocation than a known partner).
 *
 * Privacy (ADR 033): the figures are kept per hospital and shown only for the hospitals on which the viewer holds the
 * encounter grant `REVIEW` ({@see self::forHospitals()}); administrators see all. The console may print aggregates.
 *
 * @phpstan-type Outcome array{allocations: int, automatic: int, proposal: int, none: int, decisive: int, agree: int, disagree: int}
 * @phpstan-type HospitalFigures array{eligible_pairs: int, eligible_from_bonus: int, variants: array<string, Outcome>}
 */
final readonly class PlausibilityLearningReport
{
    public const string NONE = 'none';

    public const string ACTIVE = 'active';

    public const string DRAFT = 'draft';

    public const string CROSS_FITTED = 'cross_fitted';

    public const array VARIANTS = [self::NONE, self::ACTIVE, self::DRAFT, self::CROSS_FITTED];

    /**
     * @param array<int, HospitalFigures> $hospitals by hospital id
     */
    public function __construct(
        public ?int $activeSnapshotId,
        public array $hospitals,
    ) {
    }

    /**
     * Outcome of one replay against the reference.
     *
     * @param list<PlannedMatch>  $plan
     * @param list<int>           $allocationIds replayed allocations
     * @param array<string, bool> $reference     "allocationId:encounterId" => belongs together
     *
     * @return Outcome
     */
    public static function outcome(array $plan, array $allocationIds, array $reference): array
    {
        /** @var array<int, int> $partnerOfAllocation */
        $partnerOfAllocation = [];
        /** @var array<int, int> $partnerOfEncounter */
        $partnerOfEncounter = [];
        foreach ($reference as $key => $belongs) {
            if ($belongs) {
                [$allocationId, $encounterId] = array_map(intval(...), explode(':', $key));
                $partnerOfAllocation[$allocationId] = $encounterId;
                $partnerOfEncounter[$encounterId] = $allocationId;
            }
        }

        $replayed = array_flip($allocationIds);
        /** @var array<int, 'automatic'|'proposal'> $status */
        $status = [];
        $decisive = 0;
        $agree = 0;
        $disagree = 0;
        foreach ($plan as $match) {
            if (!isset($replayed[$match->allocationId])) {
                continue;
            }

            if (MatchStatus::AutoAccepted !== $match->status) {
                $status[$match->allocationId] ??= 'proposal';

                continue;
            }

            $status[$match->allocationId] = 'automatic';
            $decisive += $match->plausibilityDecisive ? 1 : 0;
            $known = $reference[$match->allocationId.':'.$match->encounterId] ?? null;
            if (true === $known) {
                ++$agree;
            } elseif (false === $known
                || (isset($partnerOfAllocation[$match->allocationId]) && $partnerOfAllocation[$match->allocationId] !== $match->encounterId)
                || (isset($partnerOfEncounter[$match->encounterId]) && $partnerOfEncounter[$match->encounterId] !== $match->allocationId)) {
                ++$disagree;
            }
        }

        $counts = array_count_values($status);
        $allocations = \count($replayed);
        $automatic = $counts['automatic'] ?? 0;
        $proposal = $counts['proposal'] ?? 0;

        return [
            'allocations' => $allocations,
            'automatic' => $automatic,
            'proposal' => $proposal,
            'none' => $allocations - $automatic - $proposal,
            'decisive' => $decisive,
            'agree' => $agree,
            'disagree' => $disagree,
        ];
    }

    /**
     * The figures of the given hospitals only; hospitals without figures are left out.
     *
     * @param list<int> $hospitalIds
     */
    public function forHospitals(array $hospitalIds): self
    {
        return new self($this->activeSnapshotId, array_intersect_key($this->hospitals, array_flip($hospitalIds)));
    }

    /**
     * Sum over the hospitals of this report (all of them for administrators and the console).
     *
     * @return HospitalFigures
     */
    public function total(): array
    {
        $eligible = 0;
        $fromBonus = 0;
        $variants = [];
        foreach ($this->hospitals as $figures) {
            $eligible += $figures['eligible_pairs'];
            $fromBonus += $figures['eligible_from_bonus'];
            foreach ($figures['variants'] as $variant => $outcome) {
                $variants[$variant] = $this->sum($variants[$variant] ?? null, $outcome);
            }
        }

        return ['eligible_pairs' => $eligible, 'eligible_from_bonus' => $fromBonus, 'variants' => $variants];
    }

    /**
     * @param Outcome|null $sum
     * @param Outcome      $outcome
     *
     * @return Outcome
     */
    private function sum(?array $sum, array $outcome): array
    {
        if (null === $sum) {
            return $outcome;
        }

        return [
            'allocations' => $sum['allocations'] + $outcome['allocations'],
            'automatic' => $sum['automatic'] + $outcome['automatic'],
            'proposal' => $sum['proposal'] + $outcome['proposal'],
            'none' => $sum['none'] + $outcome['none'],
            'decisive' => $sum['decisive'] + $outcome['decisive'],
            'agree' => $sum['agree'] + $outcome['agree'],
            'disagree' => $sum['disagree'] + $outcome['disagree'],
        ];
    }

    /**
     * @return array{active_snapshot_id: ?int, hospitals: array<int, HospitalFigures>}
     */
    public function toArray(): array
    {
        return ['active_snapshot_id' => $this->activeSnapshotId, 'hospitals' => $this->hospitals];
    }

    /**
     * @param array<array-key, mixed>|null $data as stored by {@see self::toArray()}
     */
    public static function fromArray(?array $data): ?self
    {
        if (null === $data || !\is_array($data['hospitals'] ?? null)) {
            return null;
        }

        $hospitals = [];
        foreach ($data['hospitals'] as $hospitalId => $figures) {
            if (!\is_array($figures) || !\is_array($figures['variants'] ?? null)) {
                continue;
            }

            $variants = [];
            foreach ($figures['variants'] as $variant => $outcome) {
                if (\is_string($variant) && \is_array($outcome)) {
                    $variants[$variant] = self::outcomeOf($outcome);
                }
            }

            $hospitals[(int) $hospitalId] = [
                'eligible_pairs' => self::int($figures['eligible_pairs'] ?? 0),
                'eligible_from_bonus' => self::int($figures['eligible_from_bonus'] ?? 0),
                'variants' => $variants,
            ];
        }

        $active = $data['active_snapshot_id'] ?? null;

        return new self(null === $active ? null : self::int($active), $hospitals);
    }

    /**
     * @param array<array-key, mixed> $outcome
     *
     * @return Outcome
     */
    private static function outcomeOf(array $outcome): array
    {
        return [
            'allocations' => self::int($outcome['allocations'] ?? 0),
            'automatic' => self::int($outcome['automatic'] ?? 0),
            'proposal' => self::int($outcome['proposal'] ?? 0),
            'none' => self::int($outcome['none'] ?? 0),
            'decisive' => self::int($outcome['decisive'] ?? 0),
            'agree' => self::int($outcome['agree'] ?? 0),
            'disagree' => self::int($outcome['disagree'] ?? 0),
        ];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
