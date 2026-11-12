<?php

declare(strict_types=1);

namespace App\Encounter\Application\Import;

use App\Import\Domain\ValueObject\ImportDataCoverage;

/**
 * Counts of one processed encounter file (#1048). `total` is the number of data rows; every row is
 * exactly one of inserted, duplicate, skipped or rejected.
 */
final readonly class EncounterImportResult
{
    /**
     * @param list<string> $insertedDays anchor days (Y-m-d) of the inserted visits, ascending
     */
    public function __construct(
        public int $total,
        public int $inserted,
        public int $duplicates,
        public int $skipped,
        public int $rejected,
        public int $suspectedDuplicates,
        public ImportDataCoverage $coverage,
        public array $insertedDays,
    ) {
    }
}
