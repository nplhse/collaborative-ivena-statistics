<?php

declare(strict_types=1);

namespace App\Encounter\Application\Matching;

use App\Encounter\Application\Plausibility\PlausibilityLevel;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\MatchStatus;

/**
 * A row the matcher writes: an automatic link or a proposal (ADR 030).
 *
 * Since matcher 1.2.0 a pair of pass 2 also records the plausibility bonus (contribution `plausibility`), the
 * snapshot and cell level it came from, its score with the bonus removed from every candidate of the allocation, and
 * whether the bonus was decisive: without it the pair would have stayed below the threshold of its status (ADR 030
 * amendment, ADR 033). Pairs of pass 1 and of earlier versions carry none of it and are never decisive.
 */
final readonly class PlannedMatch
{
    /**
     * @param array<string, int> $contributions points per feature
     */
    public function __construct(
        public int $allocationId,
        public int $encounterId,
        public MatchStatus $status,
        public float $score,
        public int $points,
        public array $contributions,
        public int $pass,
        public AnchorSource $timeAnchor,
        public int $timeDifferenceMinutes,
        public bool $conflict,
        /** Snapshot the bonus was read from; null in pass 1, before 1.2.0 and without active snapshot. */
        public ?int $plausibilitySnapshotId = null,
        /** Cell level of the bonus; null when no bonus was looked up (pass 1, before 1.2.0). */
        public ?PlausibilityLevel $plausibilityLevel = null,
        /** Score with the bonus removed from every candidate of the allocation in this pass; null when no bonus was looked up. */
        public ?float $scoreWithoutBonus = null,
        /** The pair would not have reached its status without the bonus. */
        public bool $plausibilityDecisive = false,
    ) {
    }
}
