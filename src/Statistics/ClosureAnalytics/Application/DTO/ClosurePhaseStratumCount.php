<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeStratum;

final readonly class ClosurePhaseStratumCount
{
    public function __construct(
        public ClosureVolumeStratum $stratum,
        public int $before,
        public int $during,
        public int $after,
    ) {
    }
}
