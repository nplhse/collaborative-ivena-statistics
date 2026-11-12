<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contracts;

/**
 * Tells My Hospitals that the imports or the projected allocations of hospitals changed. Their cached snapshots are
 * dropped and rebuilt in the background. Called by the Import and Statistics contexts, which own that data.
 */
interface HospitalDataSnapshotInvalidatorInterface
{
    /**
     * @param list<int> $hospitalIds
     */
    public function invalidate(array $hospitalIds): void;
}
