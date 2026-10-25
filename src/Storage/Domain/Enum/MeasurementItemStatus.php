<?php

declare(strict_types=1);

namespace App\Storage\Domain\Enum;

/**
 * Whether an item could be measured. Failed and unavailable items have no size; they are never reported as 0.
 */
enum MeasurementItemStatus: string
{
    case Measured = 'measured';
    case Failed = 'failed';
    case Unavailable = 'unavailable';
}
