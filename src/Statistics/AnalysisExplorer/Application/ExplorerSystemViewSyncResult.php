<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

final readonly class ExplorerSystemViewSyncResult
{
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $skipped = 0,
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->created > 0 || $this->updated > 0;
    }
}
