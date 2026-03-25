<?php

declare(strict_types=1);

namespace App\Import\Application\Contracts;

use App\Import\Application\DTO\AllocationRowDTO;

interface RowToDtoMapperInterface
{
    /**
     * @param array<string,string> $row
     */
    public function mapAssoc(array $row): AllocationRowDTO;
}
