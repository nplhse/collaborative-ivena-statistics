<?php

declare(strict_types=1);

namespace App\Analytics\Domain\Enum;

enum BrowserFamily: string
{
    case Chrome = 'Chrome';
    case Firefox = 'Firefox';
    case Safari = 'Safari';
    case Edge = 'Edge';
    case Other = 'Other';
}
