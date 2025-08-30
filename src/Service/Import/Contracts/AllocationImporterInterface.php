<?php

namespace App\Service\Import\Contracts;

use App\Entity\Import;

interface AllocationImporterInterface
{
    /**
     * @return array{total:int,ok:int,rejected:int}
     */
    public function import(Import $import): array;
}
