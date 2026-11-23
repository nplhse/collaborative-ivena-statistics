<?php

declare(strict_types=1);

namespace App\Allocation\Application\Geo;

final readonly class LegacyDistrictTakeoverReport
{
    /**
     * @param list<string> $problems
     */
    public function __construct(
        public LegacyDistrictTakeoverOutcome $outcome,
        public int $created = 0,
        public int $assigned = 0,
        public int $geometriesStored = 0,
        public array $problems = [],
    ) {
    }
}
