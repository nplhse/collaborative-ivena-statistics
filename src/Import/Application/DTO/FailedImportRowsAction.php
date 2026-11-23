<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

/**
 * What app:import:discard-failed-rows does with the rows of a failed import.
 */
enum FailedImportRowsAction: string
{
    case Discard = 'discard';
    /** The last run failed before it touched the rows, so they belong to the last successful run. */
    case KeepLastSuccessfulRun = 'keep: last successful run';
    /** Without the file the rows could never be restored. */
    case KeepSourceMissing = 'keep: source file missing';
    /** The import holds no rows; it is only listed because it replaced rows of older imports. */
    case NoRows = 'no rows';
}
