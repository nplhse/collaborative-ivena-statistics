<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

enum AnalysisSeriesMode: string
{
    case ByDimension = 'by_dimension';
    case ByMetric = 'by_metric';

    public function requiresSeriesDimension(): bool
    {
        return self::ByDimension === $this;
    }
}
