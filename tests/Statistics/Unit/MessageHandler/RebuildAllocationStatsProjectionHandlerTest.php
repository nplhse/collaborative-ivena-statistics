<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\MessageHandler;

use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\Contract\MaterializedViewRefresherInterface;
use App\Statistics\Application\Contract\ProjectionOverviewChangeDetectorInterface;
use App\Statistics\Application\Message\RebuildAllocationStatsProjection;
use App\Statistics\Application\MessageHandler\RebuildAllocationStatsProjectionHandler;
use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewGroups;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class RebuildAllocationStatsProjectionHandlerTest extends TestCase
{
    public function testInvokesRebuilderWithoutRefreshWhenNoStructuralChange(): void
    {
        $importId = 901;

        $rebuilder = $this->createMock(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->expects($this->once())
            ->method('rebuildForImport')
            ->with($importId);

        $changeDetector = $this->createMock(ProjectionOverviewChangeDetectorInterface::class);
        $changeDetector->expects($this->once())
            ->method('willIntroduceNewHospitals')
            ->with($importId)
            ->willReturn(false);

        $materializedViewRefresher = $this->createMock(MaterializedViewRefresherInterface::class);
        $materializedViewRefresher->expects($this->never())->method('refresh');

        $handler = new RebuildAllocationStatsProjectionHandler(
            $rebuilder,
            $changeDetector,
            $materializedViewRefresher,
            $this->createMock(LoggerInterface::class),
        );
        $handler(new RebuildAllocationStatsProjection($importId));
    }

    public function testRefreshesMaterializedViewsWhenNewHospitalIntroduced(): void
    {
        $importId = 902;

        $rebuilder = $this->createMock(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->expects($this->once())
            ->method('rebuildForImport')
            ->with($importId);

        $changeDetector = $this->createMock(ProjectionOverviewChangeDetectorInterface::class);
        $changeDetector->expects($this->once())
            ->method('willIntroduceNewHospitals')
            ->with($importId)
            ->willReturn(true);

        $materializedViewRefresher = $this->createMock(MaterializedViewRefresherInterface::class);
        $materializedViewRefresher->expects($this->once())
            ->method('refresh')
            ->with([StatisticsMaterializedViewGroups::OVERVIEW])
            ->willReturn([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with(
                'statistics_materialized_view.refreshed_after_structural_change',
                ['import_id' => $importId, 'reason' => 'new_hospital'],
            );

        $handler = new RebuildAllocationStatsProjectionHandler(
            $rebuilder,
            $changeDetector,
            $materializedViewRefresher,
            $logger,
        );
        $handler(new RebuildAllocationStatsProjection($importId));
    }
}
