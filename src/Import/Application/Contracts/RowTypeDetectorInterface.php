<?php

namespace App\Import\Application\Contracts;

use App\Import\Domain\Enum\AllocationRowType;

interface RowTypeDetectorInterface
{
    /**
     * @param array<string,string> $row
     */
    public function detect(array $row): ?AllocationRowType;
}
