<?php

declare(strict_types=1);

namespace App\Statistics\Application\Pivot;

enum AllocationPivotDimension: string
{
    case Gender = 'gender';
    case Urgency = 'urgency';
    case AgeGroup = 'age_group';
    case Department = 'department';

    public static function fromQuery(?string $value): ?self
    {
        return self::tryFrom(trim((string) $value));
    }
}
