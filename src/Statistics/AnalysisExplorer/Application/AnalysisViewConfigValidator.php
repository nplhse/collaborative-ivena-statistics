<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Exception\InvalidExplorerConfigException;

final readonly class AnalysisViewConfigValidator
{
    public function validate(AnalysisViewConfig $config): void
    {
        if (!\in_array($config->dimensionGrain, [AnalysisDimensionGrain::Month, AnalysisDimensionGrain::Year], true)) {
            throw new InvalidExplorerConfigException(sprintf('Unsupported dimension grain "%s".', $config->dimensionGrain->value));
        }

        if (!\in_array($config->presentation->chartType, [ChartPresentationType::Bar, ChartPresentationType::Line], true)) {
            throw new InvalidExplorerConfigException(sprintf('Unsupported chart type "%s".', $config->presentation->chartType->value));
        }
    }
}
