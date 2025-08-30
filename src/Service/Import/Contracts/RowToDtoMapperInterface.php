<?php

namespace App\Service\Import\Contracts;

use App\Service\Import\DTO\AllocationRowDTO;

interface RowToDtoMapperInterface
{
    /**
     * @param array<string,string> $row
     */
    public function mapAssoc(array $row): AllocationRowDTO;
}
