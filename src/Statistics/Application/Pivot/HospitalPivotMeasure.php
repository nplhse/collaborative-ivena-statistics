<?php

declare(strict_types=1);

namespace App\Statistics\Application\Pivot;

enum HospitalPivotMeasure: string
{
    case HospitalCount = 'hospital_count';
    case AvgBeds = 'avg_beds';
    case MinBeds = 'min_beds';
    case MaxBeds = 'max_beds';
    case TotalAllocations = 'total_allocations';
    case AvgAllocations = 'avg_allocations';
    case MinAllocations = 'min_allocations';
    case MaxAllocations = 'max_allocations';
    case RowPercent = 'row_percent';

    public static function fromQuery(?string $value): ?self
    {
        return self::tryFrom(trim((string) $value));
    }
}
