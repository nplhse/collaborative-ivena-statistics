<?php

declare(strict_types=1);

namespace App\Statistics\Application\MessageHandler;

use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\Message\RebuildAllocationStatsProjection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RebuildAllocationStatsProjectionHandler
{
    public function __construct(
        private AllocationStatsProjectionRebuildInterface $rebuilder,
    ) {
    }

    public function __invoke(RebuildAllocationStatsProjection $message): void
    {
        $this->rebuilder->rebuildForImport($message->importId);
    }
}
