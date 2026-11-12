<?php

declare(strict_types=1);

namespace App\Encounter\Application\Overview;

/**
 * Period choices of the encounter overview filter drawer (#1055).
 */
enum EncounterPeriodPreset: string
{
    /** All stored data, no bounds (default). */
    case AllTime = 'all_time';

    /** From the first day of the month eleven months before the current one, no upper bound (as in Statistics). */
    case Last12Months = 'last_12_months';

    /** One calendar year. */
    case Year = 'year';

    /** One calendar month. */
    case Month = 'month';

    /** Own first and last day. */
    case Custom = 'custom';
}
