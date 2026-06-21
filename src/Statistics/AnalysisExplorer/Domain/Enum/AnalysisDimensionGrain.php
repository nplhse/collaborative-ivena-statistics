<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum AnalysisDimensionGrain: string
{
    case Total = 'total';
    case Month = 'month';
    case Year = 'year';
    case Quarter = 'quarter';
    case Week = 'week';

    public function isTemporal(): bool
    {
        return match ($this) {
            self::Month, self::Year, self::Quarter, self::Week => true,
            self::Total => false,
        };
    }

    public function registryTemporalKey(): string
    {
        return match ($this) {
            self::Year => 'year',
            self::Quarter => 'quarter',
            self::Week => 'week',
            self::Month => 'month',
            self::Total => throw new \LogicException('Total is not a temporal registry key.'),
        };
    }
}
