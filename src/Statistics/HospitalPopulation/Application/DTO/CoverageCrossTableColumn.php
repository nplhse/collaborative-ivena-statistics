<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class CoverageCrossTableColumn
{
    public function __construct(
        public string $key,
        public string $label,
    ) {
    }
}
