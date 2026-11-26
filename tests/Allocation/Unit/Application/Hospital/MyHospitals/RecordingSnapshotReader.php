<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital\MyHospitals;

use App\Allocation\Application\Contracts\HospitalDataSnapshotReaderInterface;
use App\Allocation\Application\DTO\MyHospitals\HospitalDataSnapshot;

/**
 * Records every read and returns the given snapshots (or empty ones).
 */
final class RecordingSnapshotReader implements HospitalDataSnapshotReaderInterface
{
    /** @var list<list<int>> */
    public array $calls = [];

    /**
     * @param array<int, HospitalDataSnapshot> $snapshots
     */
    public function __construct(
        private readonly array $snapshots = [],
    ) {
    }

    #[\Override]
    public function read(array $hospitalIds): array
    {
        $this->calls[] = $hospitalIds;
        $snapshots = [];
        foreach ($hospitalIds as $id) {
            $snapshots[$id] = $this->snapshots[$id] ?? SnapshotBuilder::snapshot(hospitalId: $id);
        }

        return $snapshots;
    }
}
