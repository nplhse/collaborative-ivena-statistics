<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

/**
 * High-level scope describing which data population the statistics cover.
 * Concrete entities (e.g. which hospital) are supplied via additional context fields.
 */
enum StatisticsScope: string
{
    /** Platform-wide / aggregate (e.g. statistics dashboard overview) */
    case All = 'all';

    case Hospital = 'hospital';

    case State = 'state';

    case DispatchArea = 'dispatch_area';

    case Cohort = 'cohort';
}
