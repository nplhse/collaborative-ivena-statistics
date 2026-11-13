<?php

declare(strict_types=1);

namespace App\Encounter\Application\Matching;

use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\MatchStatus;

/**
 * A row the matcher writes: an automatic link or a proposal (ADR 030).
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
    ) {
    }
}
