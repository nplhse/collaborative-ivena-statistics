<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/** @psalm-suppress UnusedClass */
final readonly class AllocationStatsProjectionRebuilder implements AllocationStatsProjectionRebuildInterface
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function deleteForImport(int $importId): int
    {
        $deleted = $this->connection->executeStatement(
            'DELETE FROM allocation_stats_projection WHERE import_id = :importId',
            ['importId' => $importId],
        );

        $this->logger->info('allocation_stats_projection.deleted', [
            'import_id' => $importId,
            'rows' => $deleted,
        ]);

        return $deleted;
    }

    #[\Override]
    public function rebuildForImport(int $importId): void
    {
        $this->connection->beginTransaction();

        try {
            $this->deleteForImport($importId);

            $insertedRows = $this->connection->executeStatement(
                $this->insertSelectSql(),
                ['importId' => $importId],
            );

            $this->connection->commit();

            $this->logger->info('allocation_stats_projection.rebuilt', [
                'import_id' => $importId,
                'rows' => $insertedRows,
            ]);
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $this->logger->error('allocation_stats_projection.rebuild_failed', [
                'import_id' => $importId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Rebuilds projection rows in the database without loading allocations into PHP (memory-safe for large imports).
     */
    private function insertSelectSql(): string
    {
        return <<<'SQL'
INSERT INTO allocation_stats_projection (
    id,
    import_id,
    hospital_id,
    state_id,
    dispatch_area_id,
    speciality_id,
    department_id,
    occasion_id,
    assignment_id,
    infection_id,
    indication_normalized_id,
    secondary_indication_normalized_id,
    created_at,
    arrival_at,
    created_year,
    created_quarter,
    created_month,
    created_week,
    created_day,
    created_weekday,
    created_hour,
    day_time_bucket_code,
    shift_bucket_code,
    transport_time_minutes,
    age,
    gender_code,
    urgency_code,
    transport_type_code,
    hospital_tier_code,
    hospital_location_code,
    requires_resus,
    requires_cathlab,
    is_cpr,
    is_ventilated,
    is_shock,
    is_pregnant,
    is_work_accident,
    is_with_physician,
    secondary_transport_id,
    department_was_closed
)
SELECT
    a.id,
    a.import_id,
    a.hospital_id,
    a.state_id,
    a.dispatch_area_id,
    a.speciality_id,
    a.department_id,
    a.occasion_id,
    a.assignment_id,
    a.infection_id,
    a.indication_normalized_id,
    a.secondary_indication_normalized_id,
    a.created_at,
    a.arrival_at,
    EXTRACT(YEAR FROM a.created_at)::SMALLINT,
    CEIL(EXTRACT(MONTH FROM a.created_at) / 3.0)::SMALLINT,
    EXTRACT(MONTH FROM a.created_at)::SMALLINT,
    TO_CHAR(a.created_at, 'IW')::SMALLINT,
    EXTRACT(DAY FROM a.created_at)::SMALLINT,
    EXTRACT(ISODOW FROM a.created_at)::SMALLINT,
    EXTRACT(HOUR FROM a.created_at)::SMALLINT,
    CASE
        WHEN EXTRACT(HOUR FROM a.created_at)::INT < 6 THEN 1
        WHEN EXTRACT(HOUR FROM a.created_at)::INT < 12 THEN 2
        WHEN EXTRACT(HOUR FROM a.created_at)::INT < 18 THEN 3
        ELSE 4
    END,
    CASE
        WHEN EXTRACT(HOUR FROM a.created_at)::INT >= 22
            OR EXTRACT(HOUR FROM a.created_at)::INT < 6 THEN 1
        WHEN EXTRACT(HOUR FROM a.created_at)::INT < 14 THEN 2
        ELSE 3
    END,
    ROUND(EXTRACT(EPOCH FROM (a.arrival_at - a.created_at)) / 60)::INT,
    a.age,
    CASE UPPER(a.gender)
        WHEN 'M' THEN 1
        WHEN 'F' THEN 2
        WHEN 'X' THEN 3
        ELSE NULL
    END,
    a.urgency::SMALLINT,
    CASE UPPER(a.transport_type)
        WHEN 'G' THEN 1
        WHEN 'A' THEN 2
        ELSE NULL
    END,
    CASE h.tier
        WHEN 'Basic' THEN 1
        WHEN 'Extended' THEN 2
        WHEN 'Full' THEN 3
        ELSE NULL
    END,
    CASE h.location
        WHEN 'Urban' THEN 1
        WHEN 'Mixed' THEN 2
        WHEN 'Rural' THEN 3
        ELSE NULL
    END,
    a.requires_resus,
    a.requires_cathlab,
    a.is_cpr,
    a.is_ventilated,
    a.is_shock,
    a.is_pregnant,
    a.is_work_accident,
    a.is_with_physician,
    a.secondary_transport_id,
    a.department_was_closed
FROM allocation a
INNER JOIN hospital h ON h.id = a.hospital_id
WHERE a.import_id = :importId
SQL;
    }
}
