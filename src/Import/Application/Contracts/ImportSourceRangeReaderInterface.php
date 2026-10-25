<?php

declare(strict_types=1);

namespace App\Import\Application\Contracts;

use App\Import\Application\ExportPeriod\ExportPeriod;

/**
 * Reads the time span of rows that an import wrote, as naive wall-clock values.
 */
interface ImportSourceRangeReaderInterface
{
    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null first and last allocation `created_at`
     */
    public function allocationRange(int $importId): ?array;

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null first closure start and last closure end
     */
    public function closureRange(int $importId): ?array;

    /**
     * Stored export periods of completed or partial allocation imports of a hospital.
     *
     * @return list<ExportPeriod>
     */
    public function allocationExportPeriods(int $hospitalId): array;
}
