<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class AllocationCrossTable
{
    /**
     * @param list<AllocationCrossTableColumn> $columns
     * @param list<AllocationCrossTableRow>    $rows
     */
    public function __construct(
        public array $columns,
        public array $rows,
    ) {
    }
}
