<?php

declare(strict_types=1);

namespace App\Encounter\Application\Cleanup;

/**
 * Counts of a cleanup of encounters without clinical records (#1300).
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the console command, the job metrics and tests.
 */
final readonly class EncounterCleanupResult
{
    public function __construct(
        /** Hospitals that were looked at. */
        public int $hospitals = 0,
        /** Encounters removed. */
        public int $removed = 0,
        /** Of these, encounters that still had an IVENA assignment. */
        public int $removedWithAssignment = 0,
        /** Hospitals left as they were because a record was attached during the run. */
        public int $skippedHospitals = 0,
    ) {
    }

    public function plus(self $other): self
    {
        return new self(
            $this->hospitals + $other->hospitals,
            $this->removed + $other->removed,
            $this->removedWithAssignment + $other->removedWithAssignment,
            $this->skippedHospitals + $other->skippedHospitals,
        );
    }

    /**
     * @return array<string, int>
     */
    public function metrics(): array
    {
        return [
            'hospitals' => $this->hospitals,
            'removed' => $this->removed,
            'removed_with_assignment' => $this->removedWithAssignment,
            'skipped_hospitals' => $this->skippedHospitals,
        ];
    }
}
