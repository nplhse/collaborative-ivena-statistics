<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contracts;

use App\Allocation\Domain\Entity\DispatchArea;

interface DispatchAreaLookupInterface
{
    public function findById(int $id): ?DispatchArea;
}
