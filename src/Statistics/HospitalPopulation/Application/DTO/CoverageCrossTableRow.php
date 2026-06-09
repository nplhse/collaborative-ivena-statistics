<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class CoverageCrossTableRow
{
    /**
     * @param list<CoverageCrossTableCell> $cells
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $cells,
    ) {
    }
}
