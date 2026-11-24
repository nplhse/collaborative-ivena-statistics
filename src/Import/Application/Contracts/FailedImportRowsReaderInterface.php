<?php

declare(strict_types=1);

namespace App\Import\Application\Contracts;

/**
 * Reads failed allocation and closure imports that still hold rows or replaced rows of older imports (#1551).
 *
 * @psalm-type FailedImportRow = array{id: int|string, hospital_id: int|string, hospital_name: string, created_at: string, export_starts_at: ?string, export_ends_at: ?string, file_path: ?string, allocations: int|string, closure_intervals: int|string, projection_rows: int|string, replaced_rows: int|string, last_reason_code: ?string, hospital_leaves_statistics: bool|int|string}
 */
interface FailedImportRowsReaderInterface
{
    /**
     * @return list<FailedImportRow>
     */
    public function findCandidates(): array;

    /**
     * Older successful allocation imports of the hospital whose period overlaps. An unknown period counts as overlap.
     *
     * @return list<int>
     */
    public function findOlderOverlappingImportIds(int $importId, int $hospitalId, string $createdAt, ?string $periodStart, ?string $periodEnd): array;
}
