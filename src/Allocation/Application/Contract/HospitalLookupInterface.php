<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contract;

use App\Allocation\Domain\Entity\Hospital;

interface HospitalLookupInterface
{
    public function findById(int $id): ?Hospital;

    /**
     * @param list<int> $ids
     *
     * @return array<int, string> hospital id => display name
     */
    public function findNamesByIds(array $ids): array;
}
