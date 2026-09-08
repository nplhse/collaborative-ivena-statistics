<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contracts;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;

interface HospitalLookupInterface
{
    public function findById(int $id): ?Hospital;

    /**
     * @param list<int> $ids
     *
     * @return array<int, string> hospital id => display name
     */
    public function findNamesByIds(array $ids): array;

    /**
     * All hospitals of a federal state, including non-participating ones.
     *
     * @return list<Hospital>
     */
    public function findByState(State $state): array;

    /**
     * All hospitals of a dispatch area, including non-participating ones.
     *
     * @return list<Hospital>
     */
    public function findByDispatchArea(DispatchArea $dispatchArea): array;
}
