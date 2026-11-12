<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital\MyHospitals;

use App\Allocation\Application\Contracts\HospitalDataSnapshotReaderInterface;

/**
 * Knows no hospital, as after a hospital was deleted.
 */
final class ForgettingSnapshotReader implements HospitalDataSnapshotReaderInterface
{
    #[\Override]
    public function read(array $hospitalIds): array
    {
        return [];
    }
}
