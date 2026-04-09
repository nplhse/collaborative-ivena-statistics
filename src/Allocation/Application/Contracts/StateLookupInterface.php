<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contracts;

use App\Allocation\Domain\Entity\State;

interface StateLookupInterface
{
    public function findById(int $id): ?State;
}
