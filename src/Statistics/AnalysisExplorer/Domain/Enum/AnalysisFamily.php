<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

/**
 * Controlled analysis form for library navigation and view metadata.
 */
enum AnalysisFamily: string
{
    case TimeSeries = 'time_series';
    case Ranking = 'ranking';
    case Distribution = 'distribution';
    case Matrix = 'matrix';
    case Kpi = 'kpi';
    case Comparison = 'comparison';
    case StatisticalSummary = 'statistical_summary';
    case Special = 'special';
}
