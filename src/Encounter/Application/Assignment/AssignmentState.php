<?php

declare(strict_types=1);

namespace App\Encounter\Application\Assignment;

use App\Encounter\Contract\Assignment\AssignmentCandidate;
use App\Encounter\Contract\Assignment\AssignmentKind;
use App\Encounter\Contract\Assignment\AssignmentResult;

/**
 * What a reviewer or a retry compares (#1307): the kind of the assignment result and its candidate encounters
 * as an unordered set. Reasons are shown but not compared; `ambiguous` and `contradictory` with the same
 * candidates are different states.
 */
final readonly class AssignmentState
{
    /** @var list<int> sorted, without duplicates */
    public array $encounterIds;

    /**
     * @param list<int> $encounterIds in any order
     */
    public function __construct(
        public AssignmentKind $kind,
        array $encounterIds,
    ) {
        $ids = array_values(array_unique($encounterIds));
        sort($ids);
        $this->encounterIds = $ids;
    }

    public static function of(AssignmentResult $result): self
    {
        return new self($result->kind, array_map(static fn (AssignmentCandidate $candidate): int => $candidate->encounterId, $result->candidates));
    }

    /**
     * @param list<array{encounter_id: int, reason: string}> $candidates as stored in JSON
     */
    public static function stored(AssignmentKind $kind, array $candidates): self
    {
        return new self($kind, array_map(static fn (array $candidate): int => $candidate['encounter_id'], $candidates));
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->encounterIds === $other->encounterIds;
    }

    public function contains(int $encounterId): bool
    {
        return \in_array($encounterId, $this->encounterIds, true);
    }

    /**
     * Comma-separated candidate ids, as the conflict form carries them.
     *
     * @psalm-suppress PossiblyUnusedMethod Called by the conflict page template.
     */
    public function encounterIdList(): string
    {
        return implode(',', $this->encounterIds);
    }

    /**
     * Reads the state a conflict form carried; null when it is not a well-formed conflict state.
     */
    public static function fromForm(string $kind, string $encounterIds): ?self
    {
        $parsedKind = AssignmentKind::tryFrom($kind);
        if (!\in_array($parsedKind, [AssignmentKind::Ambiguous, AssignmentKind::Contradictory], true)) {
            return null;
        }

        $ids = [];
        foreach ('' === $encounterIds ? [] : explode(',', $encounterIds) as $id) {
            if (!ctype_digit($id)) {
                return null;
            }
            $ids[] = (int) $id;
        }

        return new self($parsedKind, $ids);
    }
}
