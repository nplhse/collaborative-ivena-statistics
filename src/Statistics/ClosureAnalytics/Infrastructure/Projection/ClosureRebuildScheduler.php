<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Projection;

use App\Statistics\Application\Contract\ClosureRebuildRequestInterface;
use App\Statistics\Application\Message\RebuildClosureVolumeProjection;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeClock;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Coalesces closure rebuilds. A request that arrives while a build holds the lock
 * stays open until that build claims it, so the update is not dropped.
 *
 * Claims happen only under the rebuild lock, and the lock ends with its process.
 * A request claimed by another run is therefore orphaned (that run died) and is claimed again.
 * A claimed request is consumed when the rebuild publishes and released when it fails;
 * after MAX_ATTEMPTS failed claims it is parked with failed_at until retryFailed().
 * Timestamps are Europe/Berlin wall-clock values like the rest of the project.
 *
 * @psalm-import-type RebuildRequestRow from ClosureRebuildRequestInterface
 */
final readonly class ClosureRebuildScheduler implements ClosureRebuildRequestInterface
{
    public const int MAX_ATTEMPTS = 3;

    public function __construct(
        private Connection $connection,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function requestAnalysis(?int $hospitalId): bool
    {
        return $this->request('analysis', $hospitalId);
    }

    #[\Override]
    public function requestVolume(?int $hospitalId): void
    {
        $this->request('volume', $hospitalId);
    }

    /**
     * Marks open and orphaned requests as claimed by $runId. They stay unconsumed until complete().
     * Must be called while holding the rebuild lock.
     *
     * @param list<int>|null $hospitalIds         null claims every open request; a list also claims requests for all hospitals
     * @param bool           $includeAllHospitals false leaves requests for all hospitals to a run that rebuilds them
     */
    public function claim(string $runId, string $kind, ?array $hospitalIds = null, bool $includeAllHospitals = true): ClosureRebuildClaim
    {
        if ([] === $hospitalIds) {
            return ClosureRebuildClaim::empty();
        }
        $this->parkExhaustedOrphans($runId);
        $params = ['kind' => $kind, 'now' => $this->now(), 'run' => $runId];
        $types = ['kind' => ParameterType::STRING, 'now' => ParameterType::STRING, 'run' => ParameterType::STRING];
        $hospitalSql = '';
        if (null !== $hospitalIds) {
            $hospitalSql = $includeAllHospitals
                ? 'AND (hospital_id IS NULL OR hospital_id IN (:hospital_ids))'
                : 'AND hospital_id IN (:hospital_ids)';
            $params['hospital_ids'] = $hospitalIds;
            $types['hospital_ids'] = ArrayParameterType::INTEGER;
        }
        /** @var list<array{id: int|string, hospital_id: int|string|null, claim_attempts: int|string, orphaned_by: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
UPDATE closure_rebuild_request AS r
SET claimed_at = :now, claimed_by = :run, claim_attempts = r.claim_attempts + 1
FROM (
    SELECT id, claimed_by AS orphaned_by FROM closure_rebuild_request
    WHERE kind = :kind AND consumed_at IS NULL AND failed_at IS NULL
    AND (claimed_by IS NULL OR claimed_by <> :run)
    {$hospitalSql}
) AS pending
WHERE r.id = pending.id
RETURNING r.id, r.hospital_id, r.claim_attempts, pending.orphaned_by
SQL,
            $params,
            $types,
        );
        if ([] === $rows) {
            return ClosureRebuildClaim::empty();
        }

        $all = false;
        $ids = [];
        $requestIds = [];
        $retried = [];
        $orphaned = [];
        foreach ($rows as $row) {
            $requestIds[] = (int) $row['id'];
            if (null !== $row['orphaned_by']) {
                $orphaned[] = (int) $row['id'];
            } elseif ((int) $row['claim_attempts'] > 1) {
                $retried[] = (int) $row['id'];
            }
            if (null === $row['hospital_id'] || '' === $row['hospital_id']) {
                $all = true;
                continue;
            }
            $hospitalId = (int) $row['hospital_id'];
            if (!\in_array($hospitalId, $ids, true)) {
                $ids[] = $hospitalId;
            }
        }
        sort($ids);
        sort($requestIds);
        if ([] !== $orphaned) {
            $this->logger->warning('closure_rebuild.request_orphaned', ['kind' => $kind, 'request_ids' => $orphaned, 'run' => $runId]);
        }
        if ([] !== $retried) {
            $this->logger->warning('closure_rebuild.request_retried', ['kind' => $kind, 'request_ids' => $retried, 'run' => $runId]);
        }

        return new ClosureRebuildClaim($all, $all ? [] : $ids, $requestIds);
    }

    /**
     * @param list<int> $requestIds
     */
    public function complete(array $requestIds): void
    {
        if ([] === $requestIds) {
            return;
        }
        /** @psalm-suppress InvalidArgument Doctrine DBAL array parameter types */
        $this->connection->executeStatement(
            'UPDATE closure_rebuild_request SET consumed_at = :now, last_error = NULL WHERE id IN (:ids) AND consumed_at IS NULL',
            ['now' => $this->now(), 'ids' => $requestIds],
            ['now' => ParameterType::STRING, 'ids' => ArrayParameterType::INTEGER],
        );
    }

    /**
     * Returns requests of a failed rebuild to the queue, or parks them once they used up MAX_ATTEMPTS.
     *
     * @param list<int> $requestIds
     */
    public function release(array $requestIds, \Throwable $error): void
    {
        if ([] === $requestIds) {
            return;
        }
        /** @var list<int|string> $failed */
        $failed = $this->connection->fetchFirstColumn(
            <<<'SQL'
UPDATE closure_rebuild_request
SET claimed_at = NULL,
    claimed_by = NULL,
    last_error = :error,
    failed_at = CASE WHEN claim_attempts >= :max THEN CAST(:now AS TIMESTAMP(0)) ELSE NULL END
WHERE id IN (:ids) AND consumed_at IS NULL
RETURNING CASE WHEN failed_at IS NOT NULL THEN id END
SQL,
            [
                'error' => mb_substr($error::class.': '.$error->getMessage(), 0, 2000),
                'max' => self::MAX_ATTEMPTS,
                'now' => $this->now(),
                'ids' => $requestIds,
            ],
            [
                'error' => ParameterType::STRING,
                'max' => ParameterType::INTEGER,
                'now' => ParameterType::STRING,
                'ids' => ArrayParameterType::INTEGER,
            ],
        );
        $failed = array_values(array_map(intval(...), array_filter($failed, static fn (int|string|null $id): bool => null !== $id)));
        if ([] !== $failed) {
            $this->logger->error('closure_rebuild.request_failed', ['request_ids' => $failed, 'attempts' => self::MAX_ATTEMPTS]);
        }
    }

    /**
     * True when a rebuild has something to claim: open requests or requests of a dead run.
     * Must be called while holding the rebuild lock.
     */
    public function hasPending(): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM closure_rebuild_request WHERE consumed_at IS NULL AND failed_at IS NULL LIMIT 1',
        );
    }

    #[\Override]
    public function unconsumed(?int $hospitalId): array
    {
        $sql = 'SELECT id, kind, hospital_id, requested_at, claimed_at, claimed_by, claim_attempts, failed_at, last_error FROM closure_rebuild_request WHERE consumed_at IS NULL';
        $params = [];
        $types = [];
        if (null !== $hospitalId) {
            $sql .= ' AND (hospital_id IS NULL OR hospital_id = :hospital)';
            $params['hospital'] = $hospitalId;
            $types['hospital'] = ParameterType::INTEGER;
        }
        /** @var list<array{id: int|string, kind: string, hospital_id: int|string|null, requested_at: string, claimed_at: ?string, claimed_by: ?string, claim_attempts: int|string, failed_at: ?string, last_error: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql.' ORDER BY id ASC', $params, $types);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'kind' => $row['kind'],
            'hospital_id' => null === $row['hospital_id'] ? null : (int) $row['hospital_id'],
            'requested_at' => $row['requested_at'],
            'claimed_at' => $row['claimed_at'],
            'claimed_by' => $row['claimed_by'],
            'claim_attempts' => (int) $row['claim_attempts'],
            'failed_at' => $row['failed_at'],
            'last_error' => $row['last_error'],
        ], $rows);
    }

    #[\Override]
    public function retryFailed(?int $hospitalId): int
    {
        $sql = 'UPDATE closure_rebuild_request SET failed_at = NULL, claim_attempts = 0 WHERE consumed_at IS NULL AND failed_at IS NOT NULL';
        $params = [];
        $types = [];
        if (null !== $hospitalId) {
            $sql .= ' AND (hospital_id IS NULL OR hospital_id = :hospital)';
            $params['hospital'] = $hospitalId;
            $types['hospital'] = ParameterType::INTEGER;
        }
        /** @psalm-suppress InvalidArgument Doctrine DBAL parameter types */
        $count = $this->connection->executeStatement($sql, $params, $types);
        if ($count > 0) {
            $this->messageBus->dispatch(new RebuildClosureVolumeProjection());
        }

        return $count;
    }

    /**
     * A run that dies (OOM, kill) never releases its requests. Without this, a request that kills
     * every run would be claimed again forever.
     */
    private function parkExhaustedOrphans(string $runId): void
    {
        /** @var list<int|string> $parked */
        $parked = $this->connection->fetchFirstColumn(
            <<<'SQL'
UPDATE closure_rebuild_request
SET failed_at = CAST(:now AS TIMESTAMP(0)),
    last_error = 'Rebuild run ' || claimed_by || ' ended without releasing the request.',
    claimed_at = NULL,
    claimed_by = NULL
WHERE consumed_at IS NULL AND failed_at IS NULL
AND claimed_by IS NOT NULL AND claimed_by <> :run
AND claim_attempts >= :max
RETURNING id
SQL,
            ['now' => $this->now(), 'run' => $runId, 'max' => self::MAX_ATTEMPTS],
            ['now' => ParameterType::STRING, 'run' => ParameterType::STRING, 'max' => ParameterType::INTEGER],
        );
        if ([] !== $parked) {
            $this->logger->error('closure_rebuild.request_failed', ['request_ids' => array_map(intval(...), $parked), 'attempts' => self::MAX_ATTEMPTS, 'reason' => 'orphaned']);
        }
    }

    /**
     * Queues a request unless the hospital has no closure data or an open request already covers it.
     * An open analysis request covers volume, and a request for all hospitals covers each one.
     * Claimed requests do not count: their run may have read the data before this change.
     */
    private function request(string $kind, ?int $hospitalId): bool
    {
        $covering = 'volume' === $kind ? ['analysis', 'volume'] : ['analysis'];
        /** @psalm-suppress InvalidArgument Doctrine DBAL array parameter types */
        $queued = $this->connection->fetchOne(
            <<<'SQL'
INSERT INTO closure_rebuild_request (kind, hospital_id, requested_at)
SELECT :kind, CAST(:hospital AS INT), CAST(:now AS TIMESTAMP(0))
WHERE (
    CAST(:hospital AS INT) IS NULL
    OR EXISTS (SELECT 1 FROM closure_interval WHERE hospital_id = :hospital)
    OR EXISTS (SELECT 1 FROM closure_event WHERE hospital_id = :hospital)
)
AND NOT EXISTS (
    SELECT 1 FROM closure_rebuild_request
    WHERE kind IN (:covering)
    AND consumed_at IS NULL AND failed_at IS NULL AND claimed_by IS NULL
    AND (hospital_id IS NULL OR hospital_id = :hospital)
)
RETURNING id
SQL,
            ['kind' => $kind, 'hospital' => $hospitalId, 'now' => $this->now(), 'covering' => $covering],
            [
                'kind' => ParameterType::STRING,
                'hospital' => null === $hospitalId ? ParameterType::NULL : ParameterType::INTEGER,
                'now' => ParameterType::STRING,
                'covering' => ArrayParameterType::STRING,
            ],
        );
        if (false === $queued) {
            return false;
        }
        $this->messageBus->dispatch(new RebuildClosureVolumeProjection());

        return true;
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(ClosureVolumeClock::zone())->format('Y-m-d H:i:s');
    }
}
