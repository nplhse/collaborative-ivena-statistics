<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

/**
 * How well one calendar month of a hospital is covered by imported data.
 */
enum MonthCoverageState: string
{
    /** Before the first or after the last covered day: the hospital did not deliver data for it, no gap. */
    case Outside = 'outside';
    /** No import covers the month, but earlier and later months are covered: a possible gap. */
    case Missing = 'missing';
    /** Imports cover only some days of the month, or there are allocations without a known period. */
    case Partial = 'partial';
    /** Imports cover every day of the month and it has allocations. */
    case Covered = 'covered';
    /** Imports cover every day of the month, but it has no allocations. Not a confirmed zero: see the docs. */
    case CoveredZero = 'covered_zero';
    /** The current calendar month; never complete, never compared. */
    case InProgress = 'in_progress';

    public function isFullyCovered(): bool
    {
        return self::Covered === $this || self::CoveredZero === $this;
    }
}
