<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Dataset;

use App\DataFixtures\Generator\Application\Run\GenerationRunView;

/**
 * Outcome of the synchronous stage of a dataset run. The projections continue in the Messenger worker; the
 * run (GenerationRunTracker::get()) shows when they are done.
 */
final readonly class DatasetResult
{
    /**
     * @param list<int>             $handedOver hospitals whose projections run asynchronously
     * @param array<string, string> $skipped    hospital name => reason, blocked hospitals left out of the run
     */
    public function __construct(
        public string $runId,
        public array $handedOver,
        public ?GenerationRunView $run,
        public array $skipped,
    ) {
    }

    public function hasFailures(): bool
    {
        return array_any($this->run->hospitals ?? [], fn ($hospital): bool => \App\DataFixtures\Generator\Application\Run\GenerationRunHospitalStatus::Failed === $hospital->status);
    }
}
