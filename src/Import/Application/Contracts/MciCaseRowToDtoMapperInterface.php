<?php

namespace App\Import\Application\Contracts;

use App\Import\Application\DTO\MciCaseRowDTO;

interface MciCaseRowToDtoMapperInterface
{
    /**
     * @param array<string,string> $row
     */
    public function mapAssoc(array $row): MciCaseRowDTO;
}
