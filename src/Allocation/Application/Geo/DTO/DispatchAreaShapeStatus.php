<?php

declare(strict_types=1);

namespace App\Allocation\Application\Geo\DTO;

/**
 * The one rule for a map shape: at least one district, and every assigned district has a geometry (ADR 041).
 *
 * @psalm-immutable
 */
final readonly class DispatchAreaShapeStatus
{
    /**
     * @param list<string> $missingDistricts    "AGS name" of assigned districts without a geometry
     * @param list<string> $otherStateDistricts "AGS name" of assigned districts from another state
     */
    public function __construct(
        public string $name,
        public bool $hasAllocations,
        public int $districtCount,
        public array $missingDistricts = [],
        public array $otherStateDistricts = [],
    ) {
    }

    public function hasShape(): bool
    {
        return $this->districtCount > 0 && [] === $this->missingDistricts;
    }
}
