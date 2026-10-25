<?php

declare(strict_types=1);

namespace App\Storage\Domain\Enum;

/**
 * Hints shown next to an item on the Disk Usage page (ADR 017, "Deployment layout").
 */
enum MeasurementItemFlag: string
{
    /** A previous release still has a real directory where the current release links into shared/. */
    case LeftoverInRelease = 'leftover_in_release';

    /** A path in shared/ that no symlink of the current release points to. */
    case UnusedSharedPath = 'unused_shared_path';
}
