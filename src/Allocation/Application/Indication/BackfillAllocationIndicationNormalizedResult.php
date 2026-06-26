<?php

declare(strict_types=1);

namespace App\Allocation\Application\Indication;

/**
 * Counts from {@see BackfillAllocationIndicationNormalizedService::run()}.
 */
final readonly class BackfillAllocationIndicationNormalizedResult
{
    public function __construct(
        public int $rawNormalizedSyncedFromTarget,
        public int $allocationsPrimaryUpdated,
        public int $allocationsSecondaryUpdated,
        public int $projectionRowsUpdated = 0,
    ) {
    }
}
