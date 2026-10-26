<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Presenter\Coverage;

/**
 * What the data coverage card of the import detail page can show.
 */
enum ImportCoverageState: string
{
    /** The import is queued or running; a summary follows when it finishes. */
    case Pending = 'pending';
    /** The import failed or was cancelled; its rows are not complete. */
    case Unavailable = 'unavailable';
    /** The import finished before summaries existed and was not backfilled yet. */
    case NotCalculated = 'not_calculated';
    /** The import finished without records. */
    case Empty = 'empty';
    case Available = 'available';
}
