<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

enum ImportIssueKind: string
{
    /** Import failed; `amount` is 0. */
    case Failed = 'failed';
    /** Import was cancelled; `amount` is 0. */
    case Cancelled = 'cancelled';
    /** Completed partially; `amount` = rejected rows. */
    case Rejects = 'rejects';
    /** Days without records inside the import's period (#778); `amount` = days. */
    case DayGaps = 'day_gaps';
}
