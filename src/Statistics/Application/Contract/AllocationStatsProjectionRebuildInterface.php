<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

use App\Statistics\Application\DTO\AllocationStatsProjectionRebuildResult;

interface AllocationStatsProjectionRebuildInterface
{
    public function deleteForImport(int $importId): int;

    public function rebuildForImport(int $importId): AllocationStatsProjectionRebuildResult;
}
