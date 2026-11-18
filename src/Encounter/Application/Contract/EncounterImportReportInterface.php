<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

/**
 * Diagnostics of one encounter import for users with UPLOAD (#1048): row rejects. Suspected duplicates come from
 * the schema (SuspectedDuplicateReaderInterface, #1375). Never file content, cell values, case keys or timestamps.
 */
interface EncounterImportReportInterface
{
    /**
     * @return list<array{line: int|null, errors: list<string>, profile_version: string|null}> by line
     */
    public function rejects(int $importId, int $limit): array;
}
