<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

enum DistrictBoundaryFetchStatus: string
{
    case Idle = 'idle';
    case Running = 'running';
    case Failed = 'failed';
}
