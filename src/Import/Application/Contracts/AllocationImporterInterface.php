<?php

namespace App\Import\Application\Contracts;

use App\Import\Domain\Entity\Import;

interface AllocationImporterInterface
{
    /**
     * @return array{total:int,ok:int,rejected:int}
     */
    public function import(Import $import): array;
}
