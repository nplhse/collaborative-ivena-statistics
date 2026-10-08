<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Projection;

use App\Statistics\Application\Contract\ClosureRebuildRequestInterface;
use App\Statistics\Application\Message\RebuildClosureVolumeProjection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Coalesces closure rebuilds. A request that arrives while a build holds the lock
 * stays unconsumed until that build claims it, so the update is not dropped.
 */
final readonly class ClosureRebuildScheduler implements ClosureRebuildRequestInterface
{
    public function __construct(
        private Connection $connection,
        private MessageBusInterface $messageBus,
    ) {
    }

    #[\Override]
    public function requestAnalysis(?int $hospitalId): void
    {
        $this->request('analysis', $hospitalId);
    }

    #[\Override]
    public function requestVolume(?int $hospitalId): void
    {
        $this->request('volume', $hospitalId);
    }

    /**
     * @param list<int>|null $hospitalIds null claims every open request; a list also claims requests for all hospitals
     */
    public function claim(string $kind, ?array $hospitalIds = null): ClosureRebuildClaim
    {
        if ([] === $hospitalIds) {
            return ClosureRebuildClaim::empty();
        }
        $params = ['kind' => $kind];
        $types = ['kind' => ParameterType::STRING];
        $hospitalSql = '';
        if (null !== $hospitalIds) {
            $hospitalSql = 'AND (hospital_id IS NULL OR hospital_id IN (:hospital_ids))';
            $params['hospital_ids'] = $hospitalIds;
            $types['hospital_ids'] = ArrayParameterType::INTEGER;
        }
        /** @var list<array{hospital_id: int|string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
UPDATE closure_rebuild_request
SET consumed_at = CURRENT_TIMESTAMP
WHERE id IN (
    SELECT id FROM closure_rebuild_request
    WHERE kind = :kind AND consumed_at IS NULL
    {$hospitalSql}
)
RETURNING hospital_id
SQL,
            $params,
            $types,
        );
        if ([] === $rows) {
            return ClosureRebuildClaim::empty();
        }

        $ids = [];
        foreach ($rows as $row) {
            if (null === $row['hospital_id'] || '' === $row['hospital_id']) {
                return new ClosureRebuildClaim(true, []);
            }
            $hospitalId = (int) $row['hospital_id'];
            if (!\in_array($hospitalId, $ids, true)) {
                $ids[] = $hospitalId;
            }
        }
        sort($ids);

        return new ClosureRebuildClaim(false, $ids);
    }

    private function request(string $kind, ?int $hospitalId): void
    {
        $this->connection->insert('closure_rebuild_request', [
            'kind' => $kind,
            'hospital_id' => $hospitalId,
            'requested_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            'consumed_at' => null,
        ], [
            'kind' => ParameterType::STRING,
            'hospital_id' => null === $hospitalId ? ParameterType::NULL : ParameterType::INTEGER,
            'requested_at' => ParameterType::STRING,
            'consumed_at' => ParameterType::NULL,
        ]);
        $this->messageBus->dispatch(new RebuildClosureVolumeProjection());
    }
}
