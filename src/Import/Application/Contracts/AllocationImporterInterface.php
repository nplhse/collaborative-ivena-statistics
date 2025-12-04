<?php

namespace App\Import\Application\Contracts;

use App\Import\Application\DTO\ImportSummary;
use App\Import\Domain\Entity\Import;

interface AllocationImporterInterface
{
    public function import(Import $import): ImportSummary;
}
