<?php

declare(strict_types=1);

namespace App\DataFixtures\Encounter\Application;

/**
 * A generated visit stored as encounter `encounterId`; its truth stays with the visit (#1052).
 *
 * @psalm-api Read by tests that compare stored encounters with the truth (matcher tests, #1053).
 */
final readonly class PersistedVisit
{
    public function __construct(
        public int $encounterId,
        public SyntheticVisit $visit,
    ) {
    }

    /**
     * The allocation this encounter truly belongs to, or null.
     */
    public function trueAllocationId(): ?int
    {
        return $this->visit->allocationId;
    }
}
