<?php

declare(strict_types=1);

namespace App\Statistics\Application\Pivot;

enum AllocationPivotMeasure: string
{
    case Count = 'count';
    case RowPercent = 'row_percent';

    public static function fromQuery(?string $value): ?self
    {
        return self::tryFrom(trim((string) $value));
    }
}
