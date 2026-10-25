<?php

declare(strict_types=1);

namespace App\Statistics\Application\MessageHandler;

use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\Contract\ClosureRebuildRequestInterface;
use App\Statistics\Application\Contract\MaterializedViewRefresherInterface;
use App\Statistics\Application\Contract\ProjectionOverviewChangeDetectorInterface;
use App\Statistics\Application\Message\RebuildAllocationStatsProjection;
use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewGroups;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RebuildAllocationStatsProjectionHandler
{
    public function __construct(
        private AllocationStatsProjectionRebuildInterface $rebuilder,
        private ProjectionOverviewChangeDetectorInterface $changeDetector,
        private MaterializedViewRefresherInterface $materializedViewRefresher,
        private ClosureRebuildRequestInterface $closureRebuildScheduler,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RebuildAllocationStatsProjection $message): void
    {
        $needsRefresh = $this->changeDetector->willIntroduceNewHospitals($message->importId);

        $this->rebuilder->rebuildForImport($message->importId);
        // Only closure events whose reference can reach this import's allocations need a new volume.
        $import = $this->connection->fetchAssociative(
            <<<'SQL'
SELECT i.hospital_id,
       COALESCE(i.export_starts_at, rows.first_at) AS range_from,
       COALESCE(i.export_ends_at, rows.last_at + INTERVAL '1 second') AS range_to
FROM import i
LEFT JOIN LATERAL (
    SELECT MIN(created_at) AS first_at, MAX(created_at) AS last_at
    FROM allocation_stats_projection WHERE import_id = i.id
) rows ON i.export_starts_at IS NULL OR i.export_ends_at IS NULL
WHERE i.id = :id
SQL,
            ['id' => $message->importId],
            ['id' => ParameterType::INTEGER],
        );
        if (\is_array($import) && is_numeric($import['hospital_id'])) {
            $this->closureRebuildScheduler->requestVolume(
                (int) $import['hospital_id'],
                \is_string($import['range_from']) ? new \DateTimeImmutable($import['range_from']) : null,
                \is_string($import['range_to']) ? new \DateTimeImmutable($import['range_to']) : null,
            );
        }

        if (!$needsRefresh) {
            return;
        }

        $this->materializedViewRefresher->refresh([StatisticsMaterializedViewGroups::OVERVIEW]);

        $this->logger->info('statistics_materialized_view.refreshed_after_structural_change', [
            'import_id' => $message->importId,
            'reason' => 'new_hospital',
        ]);
    }
}
