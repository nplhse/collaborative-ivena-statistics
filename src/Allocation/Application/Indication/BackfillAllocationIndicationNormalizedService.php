<?php

declare(strict_types=1);

namespace App\Allocation\Application\Indication;

use Doctrine\DBAL\Connection;

/**
 * Copies normalized indication IDs from indication_raw onto allocation rows and keeps raw.normalized in sync with raw.target.
 */
final readonly class BackfillAllocationIndicationNormalizedService
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function run(bool $dryRun = false, bool $syncRawNormalizedFromTarget = true, bool $backfillAllocations = true): BackfillAllocationIndicationNormalizedResult
    {
        $rawSynced = 0;
        $primaryUpdated = 0;
        $secondaryUpdated = 0;

        if ($syncRawNormalizedFromTarget) {
            $rawSynced = $this->countOrUpdateRawNormalizedFromTarget($dryRun);
        }

        if ($backfillAllocations) {
            $primaryUpdated = $this->countOrUpdateAllocationPrimary($dryRun);
            $secondaryUpdated = $this->countOrUpdateAllocationSecondary($dryRun);
        }

        return new BackfillAllocationIndicationNormalizedResult(
            $rawSynced,
            $primaryUpdated,
            $secondaryUpdated,
        );
    }

    private function countOrUpdateRawNormalizedFromTarget(bool $dryRun): int
    {
        $sql = <<<'SQL'
UPDATE indication_raw r
SET normalized_id = r.target_id
WHERE r.normalized_id IS NULL
  AND r.target_id IS NOT NULL
SQL;

        if ($dryRun) {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM indication_raw r WHERE r.normalized_id IS NULL AND r.target_id IS NOT NULL',
            );
        }

        return $this->connection->executeStatement($sql);
    }

    private function countOrUpdateAllocationPrimary(bool $dryRun): int
    {
        $matchSql = <<<'SQL'
SELECT COUNT(*)
FROM allocation a
INNER JOIN indication_raw r ON r.id = a.indication_raw_id
WHERE a.indication_normalized_id IS NULL
  AND COALESCE(r.normalized_id, r.target_id) IS NOT NULL
SQL;

        $updateSql = <<<'SQL'
UPDATE allocation a
SET indication_normalized_id = COALESCE(r.normalized_id, r.target_id)
FROM indication_raw r
WHERE r.id = a.indication_raw_id
  AND a.indication_normalized_id IS NULL
  AND COALESCE(r.normalized_id, r.target_id) IS NOT NULL
SQL;

        if ($dryRun) {
            return (int) $this->connection->fetchOne($matchSql);
        }

        return $this->connection->executeStatement($updateSql);
    }

    private function countOrUpdateAllocationSecondary(bool $dryRun): int
    {
        $matchSql = <<<'SQL'
SELECT COUNT(*)
FROM allocation a
INNER JOIN indication_raw r ON r.id = a.secondary_indication_raw_id
WHERE a.secondary_indication_normalized_id IS NULL
  AND COALESCE(r.normalized_id, r.target_id) IS NOT NULL
SQL;

        $updateSql = <<<'SQL'
UPDATE allocation a
SET secondary_indication_normalized_id = COALESCE(r.normalized_id, r.target_id)
FROM indication_raw r
WHERE r.id = a.secondary_indication_raw_id
  AND a.secondary_indication_normalized_id IS NULL
  AND COALESCE(r.normalized_id, r.target_id) IS NOT NULL
SQL;

        if ($dryRun) {
            return (int) $this->connection->fetchOne($matchSql);
        }

        return $this->connection->executeStatement($updateSql);
    }
}
