<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeStratum;

final readonly class ClosureProfilePhaseStratumBreakdown
{
    public function __construct(
        public ClosureVolumeStratum $stratum,
        public ?int $beforeTotal,
        public ?float $beforeMean,
        public ?int $duringTotal,
        public ?float $duringMean,
        public ?int $afterTotal,
        public ?float $afterMean,
        public int $evaluableEvents,
    ) {
    }
}
