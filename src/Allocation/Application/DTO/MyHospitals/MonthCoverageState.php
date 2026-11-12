<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

/**
 * How well one calendar month of a hospital is covered by imported data.
 */
enum MonthCoverageState: string
{
    /** No allocations and no import covers it, after the last import: no data delivered yet, no gap. */
    case Outside = 'outside';
    /** No allocations and no import covers it, between the periods of imports: a possible gap (missing import). */
    case Missing = 'missing';
    /** An import's period covers the month, but it has no allocations. Not judged as a confirmed zero. */
    case NoAllocations = 'no_allocations';
    /** Allocations, but imports cover only some days of the month (or no period is known). */
    case Partial = 'partial';
    /** Allocations, and imports cover every day of the month. */
    case Covered = 'covered';
    /** The current calendar month; never complete, never compared. */
    case InProgress = 'in_progress';

    public function isFullyCovered(): bool
    {
        return self::Covered === $this;
    }
}
