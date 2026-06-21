<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum TableLayout: string
{
    case Flat = 'flat';
    case Matrix = 'matrix';
    case MatrixMetricsAsRows = 'matrix_metrics_as_rows';
}
