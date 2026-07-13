<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contract;

use App\Allocation\Domain\Entity\DispatchArea;

interface DispatchAreaLookupInterface
{
    public function findById(int $id): ?DispatchArea;
}
