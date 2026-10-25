<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

/**
 * Why a selected import was left out of a bulk action. Reported to the user, in this order.
 */
enum ImportBulkSkipReason: string
{
    /** Already queued or processing; not queued again. */
    case Busy = 'busy';
    /** The stored source file is gone. */
    case SourceMissing = 'source_missing';
    /** The import's creator may no longer import into its hospital. */
    case CreatorPermission = 'creator_permission';
    /** Queuing the run failed; the other imports were still processed. */
    case DispatchFailed = 'dispatch_failed';
    /** The user may not change this import, or it does not exist (not told apart). */
    case Forbidden = 'forbidden';
}
