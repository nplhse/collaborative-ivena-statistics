<?php

declare(strict_types=1);

namespace App\Statistics\Domain\Enum;

enum TimeGridMode: string
{
    case RAW = 'raw';
    case DELTA = 'delta';
    case COMPARE = 'compare';
}
