<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contract;

use App\Allocation\Domain\Entity\Hospital;

interface HospitalLookupInterface
{
    public function findById(int $id): ?Hospital;
}
