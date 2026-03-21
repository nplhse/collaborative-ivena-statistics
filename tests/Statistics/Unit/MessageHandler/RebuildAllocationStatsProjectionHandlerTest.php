<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\MessageHandler;

use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\Message\RebuildAllocationStatsProjection;
use App\Statistics\Application\MessageHandler\RebuildAllocationStatsProjectionHandler;
use PHPUnit\Framework\TestCase;

final class RebuildAllocationStatsProjectionHandlerTest extends TestCase
{
    public function testInvokesRebuilderWithImportId(): void
    {
        $importId = 901;

        $rebuilder = $this->createMock(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->expects($this->once())
            ->method('rebuildForImport')
            ->with($importId);

        $handler = new RebuildAllocationStatsProjectionHandler($rebuilder);
        $handler(new RebuildAllocationStatsProjection($importId));
    }
}
