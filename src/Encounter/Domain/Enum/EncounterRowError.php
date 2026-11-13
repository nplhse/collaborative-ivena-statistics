<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * Error code of a rejected row of an encounter file (#1048). A reject records line number, field,
 * this code and the profile version, never the cell value or the row.
 */
enum EncounterRowError: string
{
    /** The row has a different number of cells than the header. */
    case ColumnCount = 'column_count';
    case Required = 'required';
    case InvalidFormat = 'invalid_format';
    case InvalidCode = 'invalid_code';
    case OutOfRange = 'out_of_range';
    /** A value contradicts another field of the row (care level, vitals reference, measurement time). */
    case Inconsistent = 'inconsistent';
}
