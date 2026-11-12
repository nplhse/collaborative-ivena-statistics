<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

/**
 * Diagnostics of one encounter import for users with UPLOAD (#1048): row rejects and suspected
 * duplicates. Never file content, cell values, case keys or timestamps.
 */
interface EncounterImportReportInterface
{
    /**
     * @return list<array{line: int|null, errors: list<string>, profile_version: string|null}> by line
     */
    public function rejects(int $importId, int $limit): array;

    /**
     * @return list<array{line: int, difference_minutes: int, anchor_source: string, existing_anchor_source: string, existing_import_id: int, existing_import_name: string}> by line
     */
    public function suspectedDuplicates(int $importId, int $limit): array;
}
