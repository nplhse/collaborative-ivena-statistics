<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout;

final readonly class ExplorerTableLayoutResolver
{
    public function resolveForConfig(AnalysisViewConfig $config): TableLayout
    {
        if (!$config->hasColumnAxis()) {
            return TableLayout::Flat;
        }

        if (\count($config->metricKeys) > 1) {
            return TableLayout::MatrixMetricsAsRows;
        }

        return TableLayout::Matrix;
    }

    public function defaultChartTypeForNewColumn(AnalysisViewConfig $config): string
    {
        if ($config->rowAxis->dimensionKey->isTemporalPrimary()) {
            return 'grouped_bar';
        }

        return 'bar';
    }
}
