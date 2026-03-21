<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

interface AllocationStatsProjectionRebuildInterface
{
    public function rebuildForImport(int $importId): void;
}
