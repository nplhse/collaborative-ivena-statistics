<?php

declare(strict_types=1);

namespace App\Statistics\Application\Pivot;

enum HospitalPivotDimension: string
{
    case State = 'state';
    case DispatchArea = 'dispatch_area';
    case Location = 'location';
    case Tier = 'tier';
    case Size = 'size';

    public static function fromQuery(?string $value): ?self
    {
        return self::tryFrom(trim((string) $value));
    }
}
