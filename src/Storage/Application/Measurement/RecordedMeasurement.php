<?php

declare(strict_types=1);

namespace App\Storage\Application\Measurement;

use App\Storage\Domain\Entity\StorageMeasurement;

final readonly class RecordedMeasurement
{
    /**
     * @param StorageMeasurement $measurement the stored row; the day's earlier row when it was replaced
     */
    public function __construct(
        public StorageAnalysis $analysis,
        public StorageMeasurement $measurement,
        public bool $replacedEarlierRun,
        public bool $announced,
    ) {
    }
}
