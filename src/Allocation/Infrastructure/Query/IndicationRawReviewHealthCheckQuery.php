<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query;

use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use Doctrine\DBAL\Connection;

final readonly class IndicationRawReviewHealthCheckQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<IndicationRawReviewHealthCheckResult>
     */
    public function runAll(): array
    {
        $results = [
            $this->result(
                'raw_total',
                'Total raw indications',
                $this->count(<<<'SQL'
SELECT COUNT(*)::int FROM indication_raw
SQL),
                IndicationRawReviewHealthCheckSeverity::Info,
            ),
        ];

        foreach (IndicationRawReviewStatus::cases() as $status) {
            $results[] = $this->result(
                'raw_by_status:'.$status->value,
                'Status: '.$status->value,
                $this->count(
                    'SELECT COUNT(*)::int FROM indication_raw WHERE review_status = :status',
                    ['status' => $status->value],
                ),
                IndicationRawReviewHealthCheckSeverity::Info,
            );
        }

        $results[] = $this->result(
            'raw_open_queue',
            'Open review queue (unreviewed + needs_review)',
            $this->count(<<<'SQL'
SELECT COUNT(*)::int
FROM indication_raw
WHERE review_status IN ('unreviewed', 'needs_review')
SQL),
            IndicationRawReviewHealthCheckSeverity::Info,
        );

        $results[] = $this->result(
            'target_not_matched',
            'Target set but status is not matched',
            $this->count(<<<'SQL'
SELECT COUNT(*)::int
FROM indication_raw
WHERE target_id IS NOT NULL
  AND review_status <> 'matched'
SQL),
            IndicationRawReviewHealthCheckSeverity::Fail,
            'Set review_status to matched or clear target_id',
        );

        $results[] = $this->result(
            'matched_without_target',
            'Matched status without target',
            $this->count(<<<'SQL'
SELECT COUNT(*)::int
FROM indication_raw
WHERE review_status = 'matched'
  AND target_id IS NULL
SQL),
            IndicationRawReviewHealthCheckSeverity::Fail,
            'Assign target or change review_status',
        );

        $results[] = $this->result(
            'normalized_without_target',
            'Normalized set without target (worklist noise)',
            $this->count(<<<'SQL'
SELECT COUNT(*)::int
FROM indication_raw
WHERE normalized_id IS NOT NULL
  AND target_id IS NULL
SQL),
            IndicationRawReviewHealthCheckSeverity::Warn,
            'Copy normalized_id to target_id or clear normalized_id',
        );

        $results[] = $this->result(
            'target_normalized_mismatch',
            'Target and normalized disagree',
            $this->count(<<<'SQL'
SELECT COUNT(*)::int
FROM indication_raw
WHERE target_id IS NOT NULL
  AND normalized_id IS NOT NULL
  AND target_id <> normalized_id
SQL),
            IndicationRawReviewHealthCheckSeverity::Fail,
            'Align target_id and normalized_id',
        );

        $results[] = $this->result(
            'needs_review_without_target',
            'Awaiting approval without proposed target',
            $this->count(<<<'SQL'
SELECT COUNT(*)::int
FROM indication_raw
WHERE review_status = 'needs_review'
  AND target_id IS NULL
SQL),
            IndicationRawReviewHealthCheckSeverity::Fail,
            'Propose a target or reset review_status',
        );

        $results[] = $this->result(
            'matched_without_reviewed_by',
            'Matched without reviewed_by (legacy audit gap)',
            $this->count(<<<'SQL'
SELECT COUNT(*)::int
FROM indication_raw
WHERE review_status = 'matched'
  AND reviewed_by_id IS NULL
SQL),
            IndicationRawReviewHealthCheckSeverity::Warn,
            'Expected for legacy rows migrated from target_id',
        );

        $results[] = $this->result(
            'target_without_first_matcher',
            'Target set without first_matched_by',
            $this->count(<<<'SQL'
SELECT COUNT(*)::int
FROM indication_raw
WHERE target_id IS NOT NULL
  AND first_matched_by_id IS NULL
SQL),
            IndicationRawReviewHealthCheckSeverity::Warn,
            'Expected when updated_by_id was null during migration',
        );

        $results[] = $this->result(
            'alloc_primary_missing_normalized',
            'Allocations missing primary indication_normalized_id',
            $this->count(<<<'SQL'
SELECT COUNT(*)::int
FROM allocation a
INNER JOIN indication_raw r ON r.id = a.indication_raw_id
WHERE COALESCE(r.normalized_id, r.target_id) IS NOT NULL
  AND a.indication_normalized_id IS NULL
SQL),
            IndicationRawReviewHealthCheckSeverity::Fail,
            'app:allocation:backfill-indications',
        );

        $results[] = $this->result(
            'alloc_secondary_missing_normalized',
            'Allocations missing secondary indication_normalized_id',
            $this->count(<<<'SQL'
SELECT COUNT(*)::int
FROM allocation a
INNER JOIN indication_raw r ON r.id = a.secondary_indication_raw_id
WHERE COALESCE(r.normalized_id, r.target_id) IS NOT NULL
  AND a.secondary_indication_normalized_id IS NULL
SQL),
            IndicationRawReviewHealthCheckSeverity::Fail,
            'app:allocation:backfill-indications',
        );

        $results[] = $this->result(
            'alloc_primary_normalized_mismatch',
            'Allocation primary normalized does not match raw',
            $this->count(<<<'SQL'
SELECT COUNT(*)::int
FROM allocation a
INNER JOIN indication_raw r ON r.id = a.indication_raw_id
WHERE a.indication_normalized_id IS NOT NULL
  AND COALESCE(r.normalized_id, r.target_id) IS NOT NULL
  AND a.indication_normalized_id <> COALESCE(r.normalized_id, r.target_id)
SQL),
            IndicationRawReviewHealthCheckSeverity::Fail,
            'app:allocation:backfill-indications',
        );

        $results[] = $this->result(
            'projection_orphan_rows',
            'Projection rows without allocation',
            $this->count(<<<'SQL'
SELECT COUNT(*)::int
FROM allocation_stats_projection p
LEFT JOIN allocation a ON a.id = p.id
WHERE a.id IS NULL
SQL),
            IndicationRawReviewHealthCheckSeverity::Warn,
            'app:statistics:rebuild-projection',
        );

        $results[] = $this->result(
            'projection_primary_mismatch',
            'Projection primary normalized differs from allocation',
            $this->count(<<<'SQL'
SELECT COUNT(*)::int
FROM allocation a
INNER JOIN allocation_stats_projection p ON p.id = a.id
WHERE a.indication_normalized_id IS NOT NULL
  AND (p.indication_normalized_id IS NULL
       OR p.indication_normalized_id <> a.indication_normalized_id)
SQL),
            IndicationRawReviewHealthCheckSeverity::Fail,
            'app:allocation:backfill-indications --rebuild-projection',
        );

        $results[] = $this->result(
            'projection_primary_null_with_alloc_set',
            'Allocation has normalized but projection primary is null',
            $this->count(<<<'SQL'
SELECT COUNT(*)::int
FROM allocation a
INNER JOIN allocation_stats_projection p ON p.id = a.id
INNER JOIN indication_raw r ON r.id = a.indication_raw_id
WHERE COALESCE(r.normalized_id, r.target_id) IS NOT NULL
  AND a.indication_normalized_id IS NOT NULL
  AND p.indication_normalized_id IS NULL
SQL),
            IndicationRawReviewHealthCheckSeverity::Fail,
            'app:allocation:backfill-indications --rebuild-projection',
        );

        $results[] = $this->result(
            'projection_secondary_mismatch',
            'Projection secondary normalized differs from allocation',
            $this->count(<<<'SQL'
SELECT COUNT(*)::int
FROM allocation a
INNER JOIN allocation_stats_projection p ON p.id = a.id
WHERE a.secondary_indication_normalized_id IS NOT NULL
  AND (p.secondary_indication_normalized_id IS NULL
       OR p.secondary_indication_normalized_id <> a.secondary_indication_normalized_id)
SQL),
            IndicationRawReviewHealthCheckSeverity::Fail,
            'app:allocation:backfill-indications --rebuild-projection',
        );

        return $results;
    }

    /**
     * @return list<int>
     */
    public function fetchSampleIds(string $checkId, int $limit = 10): array
    {
        $sql = $this->sampleSql($checkId);
        if (null === $sql) {
            return [];
        }

        /** @var list<int|string> $ids */
        $ids = $this->connection->fetchFirstColumn($sql, ['limit' => $limit]);

        return array_map(static fn (int|string $id): int => (int) $id, $ids);
    }

    private function result(
        string $id,
        string $label,
        int $count,
        IndicationRawReviewHealthCheckSeverity $severity,
        string $hint = '',
    ): IndicationRawReviewHealthCheckResult {
        return new IndicationRawReviewHealthCheckResult($id, $label, $count, $severity, $hint);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function count(string $sql, array $params = []): int
    {
        return (int) $this->connection->fetchOne($sql, $params);
    }

    private function sampleSql(string $checkId): ?string
    {
        return match ($checkId) {
            'target_not_matched' => <<<'SQL'
SELECT id FROM indication_raw
WHERE target_id IS NOT NULL AND review_status <> 'matched'
ORDER BY id ASC
LIMIT :limit
SQL,
            'matched_without_target' => <<<'SQL'
SELECT id FROM indication_raw
WHERE review_status = 'matched' AND target_id IS NULL
ORDER BY id ASC
LIMIT :limit
SQL,
            'normalized_without_target' => <<<'SQL'
SELECT id FROM indication_raw
WHERE normalized_id IS NOT NULL AND target_id IS NULL
ORDER BY id ASC
LIMIT :limit
SQL,
            'target_normalized_mismatch' => <<<'SQL'
SELECT id FROM indication_raw
WHERE target_id IS NOT NULL
  AND normalized_id IS NOT NULL
  AND target_id <> normalized_id
ORDER BY id ASC
LIMIT :limit
SQL,
            'needs_review_without_target' => <<<'SQL'
SELECT id FROM indication_raw
WHERE review_status = 'needs_review' AND target_id IS NULL
ORDER BY id ASC
LIMIT :limit
SQL,
            'matched_without_reviewed_by' => <<<'SQL'
SELECT id FROM indication_raw
WHERE review_status = 'matched' AND reviewed_by_id IS NULL
ORDER BY id ASC
LIMIT :limit
SQL,
            'target_without_first_matcher' => <<<'SQL'
SELECT id FROM indication_raw
WHERE target_id IS NOT NULL AND first_matched_by_id IS NULL
ORDER BY id ASC
LIMIT :limit
SQL,
            'alloc_primary_missing_normalized' => <<<'SQL'
SELECT a.id FROM allocation a
INNER JOIN indication_raw r ON r.id = a.indication_raw_id
WHERE COALESCE(r.normalized_id, r.target_id) IS NOT NULL
  AND a.indication_normalized_id IS NULL
ORDER BY a.id ASC
LIMIT :limit
SQL,
            'alloc_secondary_missing_normalized' => <<<'SQL'
SELECT a.id FROM allocation a
INNER JOIN indication_raw r ON r.id = a.secondary_indication_raw_id
WHERE COALESCE(r.normalized_id, r.target_id) IS NOT NULL
  AND a.secondary_indication_normalized_id IS NULL
ORDER BY a.id ASC
LIMIT :limit
SQL,
            'alloc_primary_normalized_mismatch' => <<<'SQL'
SELECT a.id FROM allocation a
INNER JOIN indication_raw r ON r.id = a.indication_raw_id
WHERE a.indication_normalized_id IS NOT NULL
  AND COALESCE(r.normalized_id, r.target_id) IS NOT NULL
  AND a.indication_normalized_id <> COALESCE(r.normalized_id, r.target_id)
ORDER BY a.id ASC
LIMIT :limit
SQL,
            'projection_orphan_rows' => <<<'SQL'
SELECT p.id FROM allocation_stats_projection p
LEFT JOIN allocation a ON a.id = p.id
WHERE a.id IS NULL
ORDER BY p.id ASC
LIMIT :limit
SQL,
            'projection_primary_mismatch' => <<<'SQL'
SELECT a.id FROM allocation a
INNER JOIN allocation_stats_projection p ON p.id = a.id
WHERE a.indication_normalized_id IS NOT NULL
  AND (p.indication_normalized_id IS NULL
       OR p.indication_normalized_id <> a.indication_normalized_id)
ORDER BY a.id ASC
LIMIT :limit
SQL,
            'projection_primary_null_with_alloc_set' => <<<'SQL'
SELECT a.id FROM allocation a
INNER JOIN allocation_stats_projection p ON p.id = a.id
INNER JOIN indication_raw r ON r.id = a.indication_raw_id
WHERE COALESCE(r.normalized_id, r.target_id) IS NOT NULL
  AND a.indication_normalized_id IS NOT NULL
  AND p.indication_normalized_id IS NULL
ORDER BY a.id ASC
LIMIT :limit
SQL,
            'projection_secondary_mismatch' => <<<'SQL'
SELECT a.id FROM allocation a
INNER JOIN allocation_stats_projection p ON p.id = a.id
WHERE a.secondary_indication_normalized_id IS NOT NULL
  AND (p.secondary_indication_normalized_id IS NULL
       OR p.secondary_indication_normalized_id <> a.secondary_indication_normalized_id)
ORDER BY a.id ASC
LIMIT :limit
SQL,
            default => null,
        };
    }
}
