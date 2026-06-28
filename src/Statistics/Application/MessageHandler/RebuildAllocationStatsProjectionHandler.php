<?php

declare(strict_types=1);

namespace App\Statistics\Application\MessageHandler;

use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\Contract\MaterializedViewRefresherInterface;
use App\Statistics\Application\Contract\ProjectionOverviewChangeDetectorInterface;
use App\Statistics\Application\Message\RebuildAllocationStatsProjection;
use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewGroups;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RebuildAllocationStatsProjectionHandler
{
    public function __construct(
        private AllocationStatsProjectionRebuildInterface $rebuilder,
        private ProjectionOverviewChangeDetectorInterface $changeDetector,
        private MaterializedViewRefresherInterface $materializedViewRefresher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RebuildAllocationStatsProjection $message): void
    {
        $needsRefresh = $this->changeDetector->willIntroduceNewHospitals($message->importId);

        $this->rebuilder->rebuildForImport($message->importId);

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
