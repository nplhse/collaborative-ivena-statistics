<?php

declare(strict_types=1);

namespace App\Analytics\Domain\Enum;

enum DeviceType: string
{
    case Desktop = 'Desktop';
    case Mobile = 'Mobile';
    case Tablet = 'Tablet';
    case Bot = 'Bot';
}
