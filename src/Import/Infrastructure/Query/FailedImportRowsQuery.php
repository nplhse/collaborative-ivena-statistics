<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Query;

use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Failed allocation and closure imports that still hold rows or replaced rows of older imports (#1551).
 *
 * @psalm-type FailedImportRow = array{id: int|string, hospital_id: int|string, hospital_name: string, created_at: string, export_starts_at: ?string, export_ends_at: ?string, file_path: ?string, allocations: int|string, closure_intervals: int|string, projection_rows: int|string, replaced_rows: int|string, last_reason_code: ?string, hospital_leaves_statistics: bool|int|string}
 */
final readonly class FailedImportRowsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<FailedImportRow>
     */
    public function findCandidates(): array
    {
        /** @var list<FailedImportRow> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT candidate.*
FROM (
    SELECT
        i.id,
        i.hospital_id,
        h.name AS hospital_name,
        i.created_at,
        i.export_starts_at,
        i.export_ends_at,
        i.file_path,
        (SELECT COUNT(*) FROM allocation a WHERE a.import_id = i.id) AS allocations,
        (SELECT COUNT(*) FROM closure_interval c WHERE c.import_id = i.id) AS closure_intervals,
        (SELECT COUNT(*) FROM allocation_stats_projection p WHERE p.import_id = i.id) AS projection_rows,
        COALESCE(i.rows_deduplicated_replaced, 0) AS replaced_rows,
        (SELECT r.reason_code FROM import_run r WHERE r.import_id = i.id ORDER BY r.created_at DESC, r.id DESC LIMIT 1) AS last_reason_code,
        NOT EXISTS (
            SELECT 1
            FROM allocation other
            INNER JOIN import other_import ON other_import.id = other.import_id
            WHERE other.hospital_id = i.hospital_id
              AND other_import.status <> :failed
        ) AS hospital_leaves_statistics
    FROM import i
    INNER JOIN hospital h ON h.id = i.hospital_id
    WHERE i.status = :failed
      AND i.type IN (:types)
) candidate
WHERE candidate.allocations > 0
   OR candidate.closure_intervals > 0
   OR candidate.replaced_rows > 0
ORDER BY candidate.id ASC
SQL,
            [
                'failed' => ImportStatus::FAILED->value,
                'types' => [ImportType::ALLOCATION->value, ImportType::CLOSURE->value],
            ],
            ['types' => ArrayParameterType::STRING],
        );

        return $rows;
    }

    /**
     * Older successful allocation imports of the hospital whose period overlaps. An unknown period counts as overlap.
     *
     * @return list<int>
     */
    public function findOlderOverlappingImportIds(int $importId, int $hospitalId, string $createdAt, ?string $periodStart, ?string $periodEnd): array
    {
        $ids = $this->connection->fetchFirstColumn(
            <<<'SQL'
SELECT o.id
FROM import o
WHERE o.hospital_id = :hospitalId
  AND o.id <> :importId
  AND o.type = :type
  AND o.status IN (:statuses)
  AND o.created_at < :createdAt
  AND (
      CAST(:periodStart AS timestamp) IS NULL
      OR CAST(:periodEnd AS timestamp) IS NULL
      OR o.export_starts_at IS NULL
      OR o.export_ends_at IS NULL
      OR (o.export_starts_at <= CAST(:periodEnd AS timestamp) AND o.export_ends_at >= CAST(:periodStart AS timestamp))
  )
ORDER BY o.id ASC
SQL,
            [
                'hospitalId' => $hospitalId,
                'importId' => $importId,
                'type' => ImportType::ALLOCATION->value,
                'statuses' => [ImportStatus::COMPLETED->value, ImportStatus::PARTIAL->value],
                'createdAt' => $createdAt,
                'periodStart' => $periodStart,
                'periodEnd' => $periodEnd,
            ],
            ['statuses' => ArrayParameterType::STRING],
        );

        return array_map(static fn (mixed $id): int => (int) $id, $ids);
    }
}
