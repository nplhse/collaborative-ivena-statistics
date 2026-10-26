<?php

declare(strict_types=1);

namespace App\Import\Application\Contracts;

use App\Import\Domain\ValueObject\ImportDataCoverage;

/**
 * Reads the daily coverage of the rows an import wrote, as naive wall-clock days.
 */
interface ImportCoverageReaderInterface
{
    /**
     * Allocations per `created_at` day.
     */
    public function allocationCoverage(int $importId): ImportDataCoverage;

    /**
     * Closure intervals active per day. An interval ending exactly at midnight does not cover the next day.
     */
    public function closureCoverage(int $importId): ImportDataCoverage;
}
