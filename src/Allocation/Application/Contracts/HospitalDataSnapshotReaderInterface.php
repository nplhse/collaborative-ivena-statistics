<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contracts;

use App\Allocation\Application\DTO\MyHospitals\HospitalDataSnapshot;

/**
 * Reads the raw data status of hospitals for My Hospitals in a fixed number of queries, whatever the number of
 * hospitals. Implemented by the Statistics context, which owns the allocation projection.
 */
interface HospitalDataSnapshotReaderInterface
{
    /**
     * @param list<int> $hospitalIds
     *
     * @return array<int, HospitalDataSnapshot> keyed by hospital id, one entry per requested id
     */
    public function read(array $hospitalIds): array;
}
