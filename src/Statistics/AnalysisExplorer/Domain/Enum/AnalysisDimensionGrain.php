<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum AnalysisDimensionGrain: string
{
    case Total = 'total';
    case Month = 'month';
    case Year = 'year';

    public function isTemporal(): bool
    {
        return match ($this) {
            self::Month, self::Year => true,
            self::Total => false,
        };
    }
}
