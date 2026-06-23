<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum BoxPlotTableColumn: string
{
    case Count = 'distribution_count';
    case Min = 'distribution_min';
    case P25 = 'distribution_p25';
    case Median = 'distribution_median';
    case P75 = 'distribution_p75';
    case Max = 'distribution_max';

    public function labelTranslationKey(): string
    {
        return 'stats.analysis_explorer.box_plot.column.'.$this->value;
    }
}
