<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference;

final readonly class IndicationGroupSyncResult
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $skipped = 0,
        public array $warnings = [],
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->created > 0 || $this->updated > 0;
    }
}
