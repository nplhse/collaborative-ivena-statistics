<?php

declare(strict_types=1);

namespace App\Statistics\Application\Projection;

use App\Statistics\Application\Projection\Dto\DeduplicationReport;
use App\Statistics\Application\Projection\Dto\DeduplicationResult;
use App\Statistics\Application\Projection\Dto\DeduplicationStrategySummary;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Removes duplicate allocations that inflate allocation_stats_projection counts.
 *
 * Duplicate detection:
 * - ENR strategy: same hospital_id + case_id_hash + event fingerprint (IVENA enr alone is
 *   not unique per hospital — numbers reset or repeat across years; only rows with identical
 *   case event data are treated as re-import duplicates).
 * - Fingerprint strategy: same hospital_id + content fingerprint when case_id_hash IS NULL.
 *
 * Keeper per group (documented business rule):
 * 1. Newest import (import.created_at DESC, import.id DESC) — latest IVENA export wins.
 * 2. Most complete row (optional fields: notes, assessment, secondary indication, occasion, infection).
 * 3. Lowest allocation.id as stable tiebreaker.
 */
final readonly class AllocationProjectionDeduplicator
{
    public const string PHASE_ANALYZE_ENR = 'analyze_enr';
    public const string PHASE_ANALYZE_FINGERPRINT = 'analyze_fingerprint';
    public const string PHASE_ANALYZE_ORPHANS = 'analyze_orphans';
    public const string PHASE_DELETE_BATCH = 'delete_batch';
    public const string PHASE_DELETE_ORPHANS = 'delete_orphans';

    private const int BATCH_SIZE = 500;
    private const int SAMPLE_LIMIT = 5;
    private const string STRATEGY_ENR = 'enr';
    private const string STRATEGY_FINGERPRINT = 'fingerprint';

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function analyze(?DeduplicationProgressCallback $progress = null): DeduplicationReport
    {
        $this->notify($progress, self::PHASE_ANALYZE_ENR, 0, 2, 'Scanning ENR duplicates…');
        $enr = $this->analyzeStrategy(self::STRATEGY_ENR);
        $this->notify(
            $progress,
            self::PHASE_ANALYZE_ENR,
            1,
            2,
            sprintf('Found %d duplicate rows in %d groups', $enr->duplicateRows, $enr->duplicateGroups),
        );
        $this->notify($progress, self::PHASE_ANALYZE_ENR, 2, 2, 'ENR analysis complete');

        $this->notify($progress, self::PHASE_ANALYZE_FINGERPRINT, 0, 2, 'Scanning fingerprint duplicates…');
        $fingerprint = $this->analyzeStrategy(self::STRATEGY_FINGERPRINT);
        $this->notify(
            $progress,
            self::PHASE_ANALYZE_FINGERPRINT,
            1,
            2,
            sprintf('Found %d duplicate rows in %d groups', $fingerprint->duplicateRows, $fingerprint->duplicateGroups),
        );
        $this->notify($progress, self::PHASE_ANALYZE_FINGERPRINT, 2, 2, 'Fingerprint analysis complete');

        $this->notify($progress, self::PHASE_ANALYZE_ORPHANS, 0, 1, 'Scanning orphan projection rows…');
        $orphanCount = $this->countOrphanProjections();
        $this->notify($progress, self::PHASE_ANALYZE_ORPHANS, 1, 1, sprintf('Found %d orphan projection rows', $orphanCount));

        $report = new DeduplicationReport(
            $enr,
            $fingerprint,
            $orphanCount,
            $this->countEnrHashGroupsSpanningMultipleYears(),
        );

        $this->logger->info('projection.deduplicate.analyzed', [
            'enr_duplicate_rows' => $enr->duplicateRows,
            'enr_duplicate_groups' => $enr->duplicateGroups,
            'fingerprint_duplicate_rows' => $fingerprint->duplicateRows,
            'fingerprint_duplicate_groups' => $fingerprint->duplicateGroups,
            'orphan_projection_rows' => $orphanCount,
            'enr_hash_groups_spanning_multiple_years' => $report->enrHashGroupsSpanningMultipleYears,
        ]);

        return $report;
    }

    public function execute(?DeduplicationProgressCallback $progress = null): DeduplicationResult
    {
        $report = $this->analyze($progress);

        $duplicateIds = $this->fetchDuplicateAllocationIds();
        $totalDuplicates = \count($duplicateIds);

        $deletedProjections = 0;
        $deletedAllocations = 0;
        $deletedAssessments = 0;

        if ($totalDuplicates > 0) {
            $batchCount = (int) ceil($totalDuplicates / self::BATCH_SIZE);
            $batchIndex = 0;

            $this->connection->beginTransaction();

            try {
                foreach (array_chunk($duplicateIds, self::BATCH_SIZE) as $batch) {
                    ++$batchIndex;
                    $this->notify(
                        $progress,
                        self::PHASE_DELETE_BATCH,
                        $batchIndex,
                        $batchCount,
                        sprintf('Batch %d/%d (projections, allocations, assessments)', $batchIndex, $batchCount),
                    );

                    $assessmentIds = $this->fetchAssessmentIdsForAllocations($batch);
                    $deletedProjections += $this->deleteByIds('allocation_stats_projection', 'id', $batch);
                    $deletedAllocations += $this->deleteByIds('allocation', 'id', $batch);
                    if ([] !== $assessmentIds) {
                        $deletedAssessments += $this->deleteByIds('assessment', 'id', $assessmentIds);
                    }
                }

                $this->notify($progress, self::PHASE_DELETE_ORPHANS, 0, 1, 'Removing orphan projection rows…');
                $deletedOrphans = $this->deleteOrphanProjections();
                $this->notify($progress, self::PHASE_DELETE_ORPHANS, 1, 1, sprintf('Removed %d orphan rows', $deletedOrphans));

                $this->connection->commit();
            } catch (\Throwable $e) {
                $this->connection->rollBack();

                $this->logger->error('projection.deduplicate.failed', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                throw $e;
            }
        } else {
            $this->notify($progress, self::PHASE_DELETE_ORPHANS, 0, 1, 'Removing orphan projection rows…');
            $this->connection->beginTransaction();

            try {
                $deletedOrphans = $this->deleteOrphanProjections();
                $this->notify($progress, self::PHASE_DELETE_ORPHANS, 1, 1, sprintf('Removed %d orphan rows', $deletedOrphans));
                $this->connection->commit();
            } catch (\Throwable $e) {
                $this->connection->rollBack();

                $this->logger->error('projection.deduplicate.failed', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        $result = new DeduplicationResult(
            $report,
            $deletedProjections,
            $deletedAllocations,
            $deletedAssessments,
            $deletedOrphans,
        );

        $this->logger->info('projection.deduplicate.deleted', [
            'deleted_projections' => $deletedProjections,
            'deleted_allocations' => $deletedAllocations,
            'deleted_assessments' => $deletedAssessments,
            'deleted_orphan_projections' => $deletedOrphans,
        ]);

        return $result;
    }

    private function analyzeStrategy(string $strategy): DeduplicationStrategySummary
    {
        $duplicateRows = $this->countDuplicateRows($strategy);
        $duplicateGroups = $this->countDuplicateGroups($strategy);
        $sampleIds = $this->fetchSampleDuplicateIds($strategy);

        return new DeduplicationStrategySummary(
            $strategy,
            $duplicateGroups,
            $duplicateRows,
            $sampleIds,
        );
    }

    private function countDuplicateRows(string $strategy): int
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM (%s) duplicates', $this->duplicateRowsSubquery($strategy)),
        );
    }

    private function countDuplicateGroups(string $strategy): int
    {
        $partitionExpr = $this->partitionKeyExpression($strategy);

        return (int) $this->connection->fetchOne(
            <<<SQL
SELECT COUNT(*) FROM (
    SELECT 1
    FROM allocation a
    INNER JOIN import i ON i.id = a.import_id
    WHERE {$this->strategyWhereClause($strategy)}
    GROUP BY {$partitionExpr}
    HAVING COUNT(*) > 1
) grouped
SQL
        );
    }

    /**
     * @return list<int>
     */
    private function fetchSampleDuplicateIds(string $strategy): array
    {
        /** @var list<int|string> $rows */
        $rows = $this->connection->fetchFirstColumn(
            sprintf(
                'SELECT id FROM (%s) duplicates ORDER BY id ASC LIMIT %d',
                $this->duplicateRowsSubquery($strategy),
                self::SAMPLE_LIMIT,
            ),
        );

        return array_map(static fn (int|string $id): int => (int) $id, $rows);
    }

    /**
     * @return list<int>
     */
    private function fetchDuplicateAllocationIds(): array
    {
        $sql = sprintf(
            <<<'SQL'
SELECT id FROM (
    %s
    UNION ALL
    %s
) all_duplicates
ORDER BY id ASC
SQL,
            $this->duplicateRowsSubquery(self::STRATEGY_ENR),
            $this->duplicateRowsSubquery(self::STRATEGY_FINGERPRINT),
        );

        /** @var list<int|string> $rows */
        $rows = $this->connection->fetchFirstColumn($sql);

        return array_map(static fn (int|string $id): int => (int) $id, $rows);
    }

    private function duplicateRowsSubquery(string $strategy): string
    {
        $partitionExpr = $this->partitionKeyExpression($strategy);
        $where = $this->strategyWhereClause($strategy);

        return <<<SQL
SELECT ranked.id
FROM (
    SELECT
        a.id,
        ROW_NUMBER() OVER (
            PARTITION BY {$partitionExpr}
            ORDER BY
                i.created_at DESC,
                i.id DESC,
                (
                    CASE WHEN a.notes IS NOT NULL AND a.notes <> '' THEN 1 ELSE 0 END
                    + CASE WHEN a.assessment_id IS NOT NULL THEN 1 ELSE 0 END
                    + CASE WHEN a.secondary_indication_raw_id IS NOT NULL THEN 1 ELSE 0 END
                    + CASE WHEN a.occasion_id IS NOT NULL THEN 1 ELSE 0 END
                    + CASE WHEN a.infection_id IS NOT NULL THEN 1 ELSE 0 END
                ) DESC,
                a.id ASC
        ) AS rn
    FROM allocation a
    INNER JOIN import i ON i.id = a.import_id
    WHERE {$where}
) ranked
WHERE ranked.rn > 1
SQL;
    }

    private function strategyWhereClause(string $strategy): string
    {
        return self::STRATEGY_ENR === $strategy
            ? 'a.case_id_hash IS NOT NULL'
            : 'a.case_id_hash IS NULL';
    }

    private function partitionKeyExpression(string $strategy): string
    {
        if (self::STRATEGY_ENR === $strategy) {
            return 'a.hospital_id, a.case_id_hash, '.$this->eventFingerprintExpression('a');
        }

        return $this->eventFingerprintExpression('a');
    }

    /**
     * Stable identity of the underlying IVENA case event (independent of import / surrogate id).
     */
    private function eventFingerprintExpression(string $alias): string
    {
        return <<<SQL
md5(concat_ws(
    '|',
    {$alias}.hospital_id::text,
    {$alias}.created_at::text,
    {$alias}.arrival_at::text,
    {$alias}.age::text,
    {$alias}.gender::text,
    {$alias}.urgency::text,
    {$alias}.speciality_id::text,
    {$alias}.department_id::text,
    {$alias}.assignment_id::text,
    {$alias}.dispatch_area_id::text,
    {$alias}.indication_raw_id::text
))
SQL;
    }

    /**
     * Groups sharing hospital + ENR hash across multiple calendar years (informational; not deduplicated).
     */
    private function countEnrHashGroupsSpanningMultipleYears(): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
SELECT COUNT(*) FROM (
    SELECT 1
    FROM allocation a
    WHERE a.case_id_hash IS NOT NULL
    GROUP BY a.hospital_id, a.case_id_hash
    HAVING COUNT(DISTINCT EXTRACT(YEAR FROM a.created_at)) > 1
) spanning_years
SQL
        );
    }

    private function countOrphanProjections(): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
SELECT COUNT(*)
FROM allocation_stats_projection p
WHERE NOT EXISTS (
    SELECT 1 FROM allocation a WHERE a.id = p.id
)
SQL
        );
    }

    private function deleteOrphanProjections(): int
    {
        $deleted = $this->connection->executeStatement(
            <<<'SQL'
DELETE FROM allocation_stats_projection p
WHERE NOT EXISTS (
    SELECT 1 FROM allocation a WHERE a.id = p.id
)
SQL
        );

        $this->logger->info('projection.deduplicate.orphans_removed', ['rows' => $deleted]);

        return $deleted;
    }

    /**
     * @param list<int> $allocationIds
     *
     * @return list<int>
     */
    private function fetchAssessmentIdsForAllocations(array $allocationIds): array
    {
        if ([] === $allocationIds) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, \count($allocationIds), '?'));

        /** @var list<int|string> $rows */
        $rows = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT assessment_id FROM allocation WHERE id IN ('.$placeholders.') AND assessment_id IS NOT NULL',
            $allocationIds,
        );

        return array_map(static fn (int|string $id): int => (int) $id, $rows);
    }

    /**
     * @param list<int> $ids
     */
    private function deleteByIds(string $table, string $column, array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, \count($ids), '?'));

        return $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE %s IN (%s)', $table, $column, $placeholders),
            $ids,
        );
    }

    private function notify(
        ?DeduplicationProgressCallback $progress,
        string $phase,
        int $current,
        int $max,
        string $message,
    ): void {
        $progress?->onProgress($phase, $current, $max, $message);
    }
}
