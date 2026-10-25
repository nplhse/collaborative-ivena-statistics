<?php

declare(strict_types=1);

namespace App\Storage\Application\Contract;

use App\Storage\Domain\Entity\StorageMeasurement;

/**
 * Persisted daily storage measurements (ADR 017, "Persistence").
 */
interface StorageMeasurementRepositoryInterface
{
    /**
     * Stores the measurement. If its day already has one, that row takes over the new values and is returned.
     */
    public function save(StorageMeasurement $measurement): StorageMeasurement;

    /**
     * The measurement of the most recent day, if any.
     */
    public function latest(): ?StorageMeasurement;

    public function findForDay(\DateTimeImmutable $day): ?StorageMeasurement;

    /**
     * Measurements from the given day on, oldest first.
     *
     * @return list<StorageMeasurement>
     */
    public function findSince(\DateTimeImmutable $day): array;
}
