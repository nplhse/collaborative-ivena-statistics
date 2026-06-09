<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class CoverageCrossTable
{
    /**
     * @param list<CoverageCrossTableColumn> $columns
     * @param list<CoverageCrossTableRow>    $rows
     */
    public function __construct(
        public array $columns,
        public array $rows,
    ) {
    }
}
